<?php

namespace App\Services\Payment;

use App\Contracts\SupportsSubscriptions;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusPaymentWebhook;
use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
use App\Events\PaymentFailed;
use App\Events\PaymentInitialized;
use App\Events\PaymentNeedsReview;
use App\Events\PaymentSucceeded;
use App\Events\PaymentWebhookReceived;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhook;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gateway payments, from opening a checkout to the ledger row being settled.
 *
 * The gateway services only translate; this decides. Every route to a settled
 * payment — the customer's return, a webhook, the reconcile sweep — comes
 * through verify(), so the rules are written once:
 *
 * - Nothing the browser carries back is believed. The vendor is asked.
 * - The amount and currency the vendor reports must match what was asked for,
 *   or the row is held for review instead of credited.
 * - A row is settled once. A second answer about it is a no-op.
 *
 * The ledger itself is still TransactionService. This only ever writes
 * through it.
 */
#[Singleton]
class PaymentService
{
    // Getters

    public function findByReference(string $reference): ?Transaction
    {
        return Transaction::query()->with('gateway')->where('reference', $reference)->first();
    }

    /**
     * The transaction a webhook is about: by our reference where the vendor
     * echoed it, otherwise by the vendor's own id for the checkout or payment.
     * Never across vendors — a Stripe webhook cannot settle a Paystack row.
     */
    public function findForWebhook(PaymentWebhook $webhook): ?Transaction
    {
        if (blank($webhook->reference)) {
            return null;
        }

        $transaction = $this->findByReference($webhook->reference);

        if (! $transaction) {
            $gateway = TransactionGateway::query()
                ->where('vendor', $webhook->vendor)
                ->where(fn ($query) => $query
                    ->where('gateway_id', $webhook->reference)
                    ->orWhere('gateway_request_id', $webhook->reference))
                ->first();

            $transaction = $gateway?->transaction;
        }

        return $transaction?->gateway?->vendor === $webhook->vendor ? $transaction : null;
    }

    /**
     * Every vendor with keys, and whether it accepted them just now. Null is
     * healthy; a string is what went wrong.
     *
     * @return array<string, ?string>
     */
    public function health(): array
    {
        $result = [];

        foreach (PaymentVendorEnum::cases() as $vendor) {
            if ($vendor->isManual() || ! $vendor->isConfigured()) {
                continue;
            }

            $result[$vendor->value] = $vendor->gateway()->ping();
        }

        return $result;
    }

    // Actions

    /**
     * Write a PROCESSING transaction and open a checkout for it.
     *
     * Returns the reason when it could not be opened, otherwise the transaction
     * with its gateway row, whose gateway_url is where the customer goes next.
     * A deposit by default; a purchase passes its own group, a DIRECT type,
     * the NONE wallet, and the model it pays for.
     *
     * @param  array<string, mixed>  $meta
     */
    public function checkout(
        User $user,
        PaymentVendorEnum $vendor,
        float $amount,
        string $description,
        TransactionGroupEnum $group = TransactionGroupEnum::DEPOSIT,
        TransactionTypeEnum $type = TransactionTypeEnum::CREDIT,
        TransactionWalletEnum $wallet = TransactionWalletEnum::BALANCE,
        ?Model $against = null,
        array $meta = [],
    ): string|Transaction {
        if ($vendor->isManual() || ! $vendor->isAvailable()) {
            return 'That payment method is not available right now.';
        }

        if (round($amount, 2) <= 0) {
            return 'The amount must be more than zero.';
        }

        $transaction = app(TransactionService::class)->record(
            $user,
            $type,
            $group,
            $amount,
            $description,
            TransactionViaEnum::PAYMENT_GATEWAY,
            $wallet,
            StatusTransaction::PROCESSING,
            $against,
            $meta,
            vendor: $vendor,
        );

        $gateway = $transaction->gateway;
        $gateway->currency = strtoupper(kDefaultCurrency()['code']);
        $gateway->expires_at = now()->addMinutes(max(5, (int) config('payments.checkout.expires_in_minutes', 60)));
        $gateway->save();

        $opened = $vendor->gateway()->initialize($transaction);

        // The vendor refused before any money could move, so the row is closed
        // as failed rather than left processing for the sweep to chase.
        if (\is_string($opened) || blank($opened['url'] ?? null)) {
            $reason = \is_string($opened) ? $opened : 'The payment provider did not return a checkout link.';

            $gateway->failure_reason = str()->limit($reason, 250);
            $gateway->save();

            app(TransactionService::class)->settle($transaction, StatusTransaction::FAILED, $reason);

            PaymentFailed::dispatch($transaction->fresh(), $reason);

            return $reason;
        }

        $gateway->gateway_url = $opened['url'];
        $gateway->gateway_id = $opened['gateway_id'] ?? $gateway->gateway_id;
        $gateway->save();

        PaymentInitialized::dispatch($transaction);

        return $transaction->setRelation('gateway', $gateway);
    }

    /**
     * Ask the vendor about a gateway transaction and settle it if the answer is
     * final.
     *
     * Returns the transaction as it now stands, or the reason the vendor could
     * not be asked. Safe to call any number of times, from anywhere, at once:
     * the row is locked while it is decided.
     */
    public function verify(Transaction $transaction): string|Transaction
    {
        // A refund row is asked about through the vendor's refund API, not its
        // payment API.
        if ($transaction->transaction_group->isRefund()) {
            return app(RefundService::class)->verifyRefund($transaction);
        }

        $transaction->loadMissing('gateway');
        $gateway = $transaction->gateway;

        if (! $gateway || ! $gateway->isAutomated()) {
            return 'This is not a gateway payment.';
        }

        // Already confirmed or refunded, or held for a person after money landed
        // on a closed row: there is nothing left the vendor could say that
        // should change it.
        if ($this->isDecided($transaction)) {
            return $transaction;
        }

        if (! $gateway->vendor->isConfigured()) {
            return "{$gateway->vendor->label()} is not configured.";
        }

        // A subscription's first payment is asked about as a subscription —
        // for PayPal it is not an order at all — and comes back knowing the
        // vendor's subscription id where there already is one.
        $driver = $gateway->vendor->gateway();

        $result = $driver instanceof SupportsSubscriptions && $transaction->transactionable_type === (new Subscription)->getMorphClass()
            ? $driver->verifySubscriptionCheckout($gateway)
            : $driver->verify($gateway);

        if (\is_string($result)) {
            return $result;
        }

        return DB::transaction(function () use ($transaction, $result) {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $gateway = $locked->gateway;

            // Decided by whoever held the lock first.
            if ($this->isDecided($locked)) {
                return $locked;
            }

            $gateway->verified_at = now();
            $gateway->channel = $result['channel'] ?? $gateway->channel;
            $gateway->gateway_request_id = $result['payment_id'] ?? $gateway->gateway_request_id;
            $gateway->paid_at = $result['paid_at'] ?? $gateway->paid_at;

            $this->rememberVerification($locked, $result['raw']);

            match (true) {
                $result['status']->isConfirmed() => $this->confirm($locked, $gateway, $result),
                $result['status']->isFailed(), $result['status']->isCancelled() => $this->close($locked, $gateway, $result['status'], $result['message']),
                default => $gateway->save(),
            };

            return $locked->setRelation('gateway', $gateway);
        }, 3);
    }

    /**
     * Store a verified webhook and queue it. Null when this exact delivery is
     * already on file — the vendor retried something we had already accepted.
     */
    public function receiveWebhook(PaymentVendorEnum $vendor, Request $request): ?PaymentWebhook
    {
        $parsed = $vendor->gateway()->parseWebhook($request);

        try {
            $webhook = PaymentWebhook::query()->create([
                'vendor' => $vendor,
                'event_key' => str()->limit($parsed['event_key'], 185, ''),
                'event_name' => $parsed['event_name'] ? str()->limit($parsed['event_name'], 185, '') : null,
                'topic' => $parsed['topic'],
                'reference' => $parsed['reference'] ? str()->limit((string) $parsed['reference'], 195, '') : null,
                'payload' => app(PaymentTraceService::class)->redact((array) $request->json()->all()),
                'status' => StatusPaymentWebhook::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        ProcessPaymentWebhook::dispatch($webhook);

        return $webhook;
    }

    /**
     * Work one stored webhook. Throws when the vendor could not be asked, so
     * the queue retries it with backoff; everything else ends in a status.
     */
    public function processWebhook(PaymentWebhook $webhook): void
    {
        $webhook->increment('attempts');

        if (! $webhook->status->isPending() && ! $webhook->status->isFailed()) {
            return;
        }

        $outcome = match (true) {
            $webhook->topic->isCharge() => $this->handleChargeWebhook($webhook),
            $webhook->topic->isRefund() => app(RefundService::class)->handleWebhook($webhook),
            $webhook->topic->isSubscription() => app(SubscriptionService::class)->handleWebhook($webhook),
            default => 'No handler for this event.',
        };

        $webhook->status = $outcome === null ? StatusPaymentWebhook::PROCESSED : StatusPaymentWebhook::IGNORED;
        $webhook->last_error = $outcome;
        $webhook->processed_at = now();
        $webhook->save();

        PaymentWebhookReceived::dispatch($webhook);
    }

    /**
     * Mark a webhook as failed once the queue has given up on it.
     */
    public function failWebhook(PaymentWebhook $webhook, string $error): void
    {
        $webhook->status = StatusPaymentWebhook::FAILED;
        $webhook->last_error = str()->limit($error, 1000);
        $webhook->save();
    }

    /**
     * Put a webhook back on the queue. Returns the reason it cannot be, or null.
     */
    public function retryWebhook(PaymentWebhook $webhook): ?string
    {
        if (! $webhook->isRetryable()) {
            return 'Only a pending or failed webhook can be retried.';
        }

        $webhook->status = StatusPaymentWebhook::PENDING;
        $webhook->last_error = null;
        $webhook->save();

        ProcessPaymentWebhook::dispatch($webhook);

        return null;
    }

    /**
     * Ask the vendor about every checkout still processing after the window,
     * and close the ones the vendor still reports unpaid long after they
     * expired. The safety net for a webhook that never came.
     *
     * @return array{checked: int, confirmed: int, closed: int, abandoned: int, errors: int}
     */
    public function reconcile(?int $afterMinutes = null, ?int $abandonAfterHours = null, bool $dryRun = false): array
    {
        $afterMinutes ??= (int) config('payments.reconcile.after_minutes', 15);
        $abandonAfterHours ??= (int) config('payments.reconcile.abandon_after_hours', 24);

        $counts = ['checked' => 0, 'confirmed' => 0, 'closed' => 0, 'abandoned' => 0, 'errors' => 0];

        Transaction::query()
            ->with('gateway')
            ->where('via', TransactionViaEnum::PAYMENT_GATEWAY)
            ->where('status', StatusTransaction::PROCESSING)
            ->where('created_at', '<=', now()->subMinutes($afterMinutes))
            // A row held for review is waiting on a person, not on the vendor.
            ->whereHas('gateway', fn ($query) => $query
                ->where('vendor', '!=', PaymentVendorEnum::MANUAL)
                ->whereNull('failure_reason'))
            ->chunkById(100, function ($transactions) use (&$counts, $abandonAfterHours, $dryRun) {
                foreach ($transactions as $transaction) {
                    $counts['checked']++;

                    if ($dryRun) {
                        continue;
                    }

                    $result = $this->verify($transaction);

                    if (\is_string($result)) {
                        $counts['errors']++;

                        continue;
                    }

                    if ($result->status->isConfirmed()) {
                        $counts['confirmed']++;

                        continue;
                    }

                    if ($result->status->isFailed() || $result->status->isCancelled()) {
                        $counts['closed']++;

                        continue;
                    }

                    $expiredAt = $result->gateway->expires_at ?? $result->created_at;

                    // A refund still on its way is the vendor's to finish; only an
                    // unpaid checkout is ever given up on.
                    if ($result->transaction_group->isRefund()) {
                        continue;
                    }

                    if ($result->status->isProcessing() && ! $result->gateway->failure_reason && $expiredAt->lte(now()->subHours($abandonAfterHours))) {
                        $this->close($result, $result->gateway, StatusTransaction::CANCELLED, 'The checkout was never completed.');
                        $counts['abandoned']++;
                    }
                }
            });

        return $counts;
    }

    // Tools

    protected function isDecided(Transaction $transaction): bool
    {
        return $transaction->status->isConfirmed()
            || $transaction->status->isRefunded()
            || $transaction->status->isConfirmedButNotLogged();
    }

    /**
     * @param  array{status: StatusTransaction, amount: ?float, currency: ?string, message: ?string}  $result
     */
    protected function confirm(Transaction $transaction, TransactionGateway $gateway, array $result): void
    {
        $expected = round((float) $transaction->amount, 2);
        $received = $result['amount'] !== null ? round($result['amount'], 2) : null;

        $reason = match (true) {
            // Paid after the row was already closed — the customer came back to
            // an expired checkout, or a cancel raced the payment. The money is
            // real, the row says otherwise, and only a person should decide.
            $transaction->isSettled() => 'Paid after the checkout had been closed as '.$transaction->status->label(true).'.',
            $received === null => 'The provider confirmed the payment without an amount.',
            $received < $expected => 'Paid '.kMoney($received).' against '.kMoney($expected).' requested.',
            $gateway->currency && $result['currency'] && strtoupper($gateway->currency) !== $result['currency'] => "Paid in {$result['currency']} against {$gateway->currency} requested.",
            default => null,
        };

        if ($reason !== null) {
            $isNew = $gateway->failure_reason !== $reason;

            $gateway->failure_reason = str()->limit($reason, 250);
            $gateway->save();

            if ($transaction->isSettled()) {
                $transaction->status = StatusTransaction::CONFIRMED_BUT_NOT_LOGGED;
                $transaction->save();
            }

            if ($isNew) {
                PaymentNeedsReview::dispatch($transaction, $reason);
            }

            return;
        }

        $gateway->failure_reason = null;
        $gateway->paid_at ??= now();
        $gateway->save();

        app(TransactionService::class)->settle($transaction, StatusTransaction::CONFIRMED, $gateway->vendor->label());

        PaymentSucceeded::dispatch($transaction);

        if ($transaction->transactionable instanceof Subscription) {
            app(SubscriptionService::class)->activate($transaction->transactionable, $result);
        }
    }

    protected function close(Transaction $transaction, TransactionGateway $gateway, StatusTransaction $status, ?string $reason): void
    {
        $gateway->failure_reason = $reason ? str()->limit($reason, 250) : $gateway->failure_reason;
        $gateway->save();

        if ($transaction->isSettled()) {
            return;
        }

        app(TransactionService::class)->settle($transaction, $status, $reason);

        PaymentFailed::dispatch($transaction, $reason);

        if ($transaction->transactionable instanceof Subscription) {
            app(SubscriptionService::class)->checkoutClosed($transaction->transactionable);
        }
    }

    /**
     * What the vendor said, kept on the transaction's meta row with the
     * credentials stripped, so support can see it without tracing switched on.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function rememberVerification(Transaction $transaction, array $raw): void
    {
        $meta = $transaction->meta()->firstOrNew();

        $meta->content = [
            ...(array) $meta->content,
            'gateway' => app(PaymentTraceService::class)->redact($raw),
        ];

        $meta->save();
    }

    /**
     * Null when the webhook was acted on; otherwise why it was not.
     */
    protected function handleChargeWebhook(PaymentWebhook $webhook): ?string
    {
        $transaction = $this->findForWebhook($webhook);

        // A charge with no row of ours behind it may be a subscription renewal
        // the vendor took on its own schedule (Paystack, Flutterwave).
        if (! $transaction) {
            return $webhook->vendor->gateway() instanceof SupportsSubscriptions
                ? app(SubscriptionService::class)->handleWebhook($webhook)
                : 'No transaction matches this webhook.';
        }

        $result = $this->verify($transaction);

        if (\is_string($result)) {
            throw new RuntimeException($result);
        }

        return null;
    }
}
