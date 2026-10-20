<?php

namespace App\Services\Payment;

use App\Contracts\SupportsSubscriptions;
use App\Enums\LogChannelEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusSubscription;
use App\Enums\StatusTransaction;
use App\Enums\SubscriptionFeatureEnum;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
use App\Events\PaymentFailed;
use App\Events\PaymentInitialized;
use App\Events\PaymentNeedsReview;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionEnded;
use App\Events\SubscriptionPaymentFailed;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\PaymentWebhook;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\SubscriptionPlanVendor;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use App\Models\User;
use App\Services\TransactionService;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Subscriptions billed by the vendor.
 *
 * The vendor owns the schedule, the card and the retries. The kit keeps a
 * Subscription row in step with it and writes a ledger row — DIRECT, NONE
 * wallet, SUBSCRIPTION group, against the subscription — for every payment:
 * the first one through the ordinary checkout and verify(), each renewal from
 * a webhook that is checked with the vendor before it is written.
 *
 * What an account may do is asked by feature, never by plan name:
 * allows() and limitFor() read the plan accessFor() finds, falling back to
 * the free plan. Trials are the kit's own — no vendor, no card — and each
 * account gets one.
 */
#[Singleton]
class SubscriptionService
{
    // Getters

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    public function activePlans(): Collection
    {
        return SubscriptionPlan::query()
            ->with(['prices', 'features', 'includedPlan.features', 'includedPlan.includedPlan.features'])
            ->active()
            ->cheapestFirst()
            ->get();
    }

    /**
     * What an account without a subscription has, if a plan is marked free.
     */
    public function freePlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()->with(['features', 'includedPlan.features'])->active()->free()->first();
    }

    /**
     * The plan an account is on now: the one its subscription or trial gives,
     * otherwise the free plan, otherwise none.
     */
    public function planFor(User $user): ?SubscriptionPlan
    {
        return $this->accessFor($user)?->plan ?? $this->freePlan();
    }

    /**
     * The check a project gates an on/off feature on.
     */
    public function allows(User $user, SubscriptionFeatureEnum $feature): bool
    {
        return (bool) $this->planFor($user)?->grants($feature);
    }

    /**
     * How many of a limit feature the account may have: null for unlimited, 0
     * when its plan does not grant it.
     */
    public function limitFor(User $user, SubscriptionFeatureEnum $feature): ?int
    {
        $plan = $this->planFor($user);

        return $plan ? $plan->limitOf($feature) : 0;
    }

    /**
     * One trial an account, whichever plan it was on. Trials are the only
     * subscriptions without a vendor.
     */
    public function hasUsedTrial(User $user): bool
    {
        return $user->subscriptions()->whereNull('vendor')->exists();
    }

    /**
     * The vendors a subscription can be taken out with right now: configured,
     * switched on, and billing subscriptions themselves.
     *
     * @return array<int, PaymentVendorEnum>
     */
    public function vendorOptions(): array
    {
        return array_values(array_filter(
            PaymentVendorEnum::checkoutOptions(),
            fn (PaymentVendorEnum $vendor) => $vendor->gateway() instanceof SupportsSubscriptions,
        ));
    }

    /**
     * The subscription that gives this account access now, if any. The one
     * check a project gates its paid features on.
     */
    public function accessFor(User $user, ?SubscriptionPlan $plan = null): ?Subscription
    {
        return $user->subscriptions()
            ->with(['plan.features', 'plan.includedPlan.features'])
            ->current()
            ->when($plan, fn ($query) => $query->where('subscription_plan_id', $plan->id))
            ->newestFirst()
            ->get()
            ->first(fn (Subscription $subscription) => $subscription->grantsAccess());
    }

    /**
     * Why this account cannot subscribe to this plan, or null when it can.
     */
    public function subscribeBlockedReason(User $user, SubscriptionPlanPrice $price, PaymentVendorEnum $vendor): ?string
    {
        $access = $this->accessFor($user);

        return match (true) {
            ! $price->plan->status->isActive(), ! $price->status->isActive() => 'That plan is not available.',
            $price->plan->is_free->isYes() => 'That plan is free — there is nothing to subscribe to.',
            ! \in_array($vendor, $this->vendorOptions(), true) => 'That payment method cannot take subscriptions.',
            // A trial gives way to a paid subscription; anything else has to be
            // cancelled first.
            $access !== null && ! $access->isTrial() => 'You already have an active subscription. Cancel it before starting another.',
            default => null,
        };
    }

    /**
     * Why this account cannot start a trial of this plan, or null when it can.
     */
    public function trialBlockedReason(User $user, SubscriptionPlan $plan): ?string
    {
        return match (true) {
            ! $plan->status->isActive() => 'That plan is not available.',
            ! $plan->hasTrial() => 'That plan has no free trial.',
            $this->hasUsedTrial($user) => 'You have already had a free trial.',
            $this->accessFor($user) !== null => 'You already have an active subscription.',
            default => null,
        };
    }

    // Actions

    /**
     * Start the plan's trial: a TRIALING row that bills nothing and runs out
     * on its own. Returns why it could not, or the trial.
     */
    public function startTrial(User $user, SubscriptionPlan $plan): string|Subscription
    {
        if ($blocked = $this->trialBlockedReason($user, $plan)) {
            return $blocked;
        }

        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 0,
            'currency' => strtoupper(kDefaultCurrency()['code']),
            'status' => StatusSubscription::TRIALING,
            'current_period_ends_at' => now()->addDays($plan->trial_days),
        ]);

        SubscriptionStarted::dispatch($subscription);

        return $subscription;
    }

    /**
     * The price as the vendor knows it, created there on first use.
     */
    public function vendorPlan(SubscriptionPlanPrice $price, PaymentVendorEnum $vendor): string|SubscriptionPlanVendor
    {
        $existing = $price->vendorPlans()->where('vendor', $vendor)->first();

        if ($existing && $existing->vendor_plan_id !== '') {
            return $existing;
        }

        $gateway = $vendor->gateway();

        if (! $gateway instanceof SupportsSubscriptions) {
            return "{$vendor->label()} does not bill subscriptions.";
        }

        // The product belongs to the plan, so a new price — or a row emptied by
        // a price change — hangs off whichever product the plan already has
        // there. A vendor's dashboard keeps one product a plan.
        $productDonor = $existing ?? SubscriptionPlanVendor::query()
            ->where('subscription_plan_id', $price->subscription_plan_id)
            ->where('vendor', $vendor)
            ->whereNotNull('vendor_product_id')
            ->first();

        $synced = $gateway->syncPlan($price, $productDonor);

        if (\is_string($synced)) {
            return $synced;
        }

        return SubscriptionPlanVendor::query()->updateOrCreate(
            ['subscription_plan_price_id' => $price->id, 'vendor' => $vendor],
            [
                'subscription_plan_id' => $price->subscription_plan_id,
                'vendor_plan_id' => $synced['vendor_plan_id'],
                'vendor_product_id' => $synced['vendor_product_id'],
            ],
        );
    }

    /**
     * Forget the vendor copies of a price whose amount or currency just
     * changed, keeping the products they hang off. Existing subscribers keep
     * the vendor plan they signed up to; the next new subscriber gets a fresh one.
     */
    public function priceChanged(SubscriptionPlanPrice $price): void
    {
        $price->vendorPlans()->update(['vendor_plan_id' => '']);
    }

    /**
     * Start a subscription: a PENDING row, the first payment's PROCESSING
     * transaction, and the vendor's checkout. Returns the reason it could not
     * be opened, or the transaction whose gateway_url the member goes to.
     */
    public function subscribe(User $user, SubscriptionPlanPrice $price, PaymentVendorEnum $vendor): string|Transaction
    {
        if ($blocked = $this->subscribeBlockedReason($user, $price, $vendor)) {
            return $blocked;
        }

        $plan = $price->plan;
        $vendorPlan = $this->vendorPlan($price, $vendor);

        if (\is_string($vendorPlan)) {
            return $vendorPlan;
        }

        [$subscription, $transaction] = DB::transaction(function () use ($user, $plan, $price, $vendor, $vendorPlan) {
            $subscription = Subscription::query()->create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'subscription_plan_price_id' => $price->id,
                'vendor' => $vendor,
                'vendor_plan_id' => $vendorPlan->vendor_plan_id,
                'amount' => (float) $price->amount,
                'currency' => $price->currency,
                'billing_interval' => $price->billing_interval,
                'status' => StatusSubscription::PENDING,
            ]);

            $transaction = app(TransactionService::class)->record(
                $user,
                TransactionTypeEnum::DIRECT,
                TransactionGroupEnum::SUBSCRIPTION,
                (float) $price->amount,
                "{$plan->name} subscription ({$price->billing_interval->label(true)})",
                TransactionViaEnum::PAYMENT_GATEWAY,
                TransactionWalletEnum::NONE,
                StatusTransaction::PROCESSING,
                $subscription,
                vendor: $vendor,
            );

            $transaction->gateway->currency = $price->currency;
            $transaction->gateway->expires_at = now()->addMinutes(max(5, (int) config('payments.checkout.expires_in_minutes', 60)));
            $transaction->gateway->save();

            return [$subscription, $transaction];
        });

        $opened = $vendor->gateway()->subscribe($subscription, $transaction, $vendorPlan->vendor_plan_id);

        if (\is_string($opened) || blank($opened['url'] ?? null)) {
            $reason = \is_string($opened) ? $opened : 'The payment provider did not return a checkout link.';

            $transaction->gateway->failure_reason = str()->limit($reason, 250);
            $transaction->gateway->save();

            app(TransactionService::class)->settle($transaction, StatusTransaction::FAILED, $reason);
            $this->checkoutClosed($subscription);

            PaymentFailed::dispatch($transaction->fresh(), $reason);

            return $reason;
        }

        $transaction->gateway->gateway_url = $opened['url'];
        $transaction->gateway->gateway_id = $opened['gateway_id'] ?? $transaction->gateway->gateway_id;
        $transaction->gateway->save();

        PaymentInitialized::dispatch($transaction);

        return $transaction;
    }

    /**
     * The first payment was confirmed: the subscription starts. Called by
     * PaymentService inside its lock, right after the row settles.
     *
     * @param  array<string, mixed>  $verification
     */
    public function activate(Subscription $subscription, array $verification): void
    {
        if (! $subscription->status->isPending()) {
            return;
        }

        $subscription->status = StatusSubscription::ACTIVE;
        $subscription->vendor_subscription_id ??= $verification['subscription_id'] ?? null;
        $subscription->vendor_customer_id ??= $verification['customer_id'] ?? null;
        $subscription->current_period_ends_at = $subscription->billing_interval->addTo(now());
        $subscription->save();

        // Paying ends a trial there and then — the paid period starts today. It
        // is not announced as ended: nothing was taken away.
        Subscription::query()
            ->where('user_id', $subscription->user_id)
            ->trialing()
            ->update(['status' => StatusSubscription::EXPIRED, 'ended_at' => now()]);

        SubscriptionStarted::dispatch($subscription);
    }

    /**
     * The first payment failed or was never made: nothing started.
     */
    public function checkoutClosed(Subscription $subscription): void
    {
        if (! $subscription->status->isPending()) {
            return;
        }

        $subscription->status = StatusSubscription::EXPIRED;
        $subscription->ended_at = now();
        $subscription->save();
    }

    /**
     * Read the subscription back from the vendor and bring the row into line.
     */
    public function refresh(Subscription $subscription): string|Subscription
    {
        $gateway = $subscription->vendor->gateway();

        if (! $gateway instanceof SupportsSubscriptions) {
            return "{$subscription->vendor->label()} does not bill subscriptions.";
        }

        $state = $gateway->fetchSubscription($subscription);

        if (\is_string($state)) {
            return $state;
        }

        $this->applyState($subscription, $state);

        return $subscription;
    }

    /**
     * Stop the vendor billing it. The time already paid for still runs out on
     * its own; the row is CANCELLED until then and EXPIRED after.
     */
    public function cancel(Subscription $subscription): ?string
    {
        if (! $subscription->canBeCancelled()) {
            return 'This subscription is not active.';
        }

        $gateway = $subscription->vendor->gateway();

        if (! $gateway instanceof SupportsSubscriptions) {
            return "{$subscription->vendor->label()} does not bill subscriptions.";
        }

        if ($error = $gateway->cancelSubscription($subscription)) {
            return $error;
        }

        $subscription->status = StatusSubscription::CANCELLED;
        $subscription->cancel_at_period_end = true;
        $subscription->cancelled_at = now();
        $subscription->save();

        SubscriptionCancelled::dispatch($subscription);

        return null;
    }

    /**
     * Work a webhook as a subscription event. Null when it was acted on;
     * otherwise why not. Throws when the vendor could not be asked, so the
     * queue retries.
     */
    public function handleWebhook(PaymentWebhook $webhook): ?string
    {
        $gateway = $webhook->vendor->gateway();

        if (! $gateway instanceof SupportsSubscriptions) {
            return 'This vendor does not bill subscriptions.';
        }

        $event = $gateway->parseSubscriptionEvent($webhook);

        if (! $event) {
            return 'Not a subscription event this application acts on.';
        }

        $subscription = $this->findForEvent($webhook->vendor, $event);

        if (! $subscription) {
            return 'No subscription matches this webhook.';
        }

        // Learn the vendor's ids the first time they show up.
        $subscription->vendor_subscription_id ??= $event['vendor_subscription_id'];
        $subscription->vendor_token = $event['token'] ?? $subscription->vendor_token;
        $subscription->save();

        if ($event['event']->isRenewed()) {
            return $this->recordRenewal($subscription, (string) $event['payment_id'], $event['period_ends_at']);
        }

        if ($event['event']->isPaymentFailed()) {
            if (! $subscription->status->isPastDue()) {
                $subscription->status = StatusSubscription::PAST_DUE;
                $subscription->save();

                SubscriptionPaymentFailed::dispatch($subscription);
            }

            return null;
        }

        $refreshed = $this->refresh($subscription);

        if (\is_string($refreshed)) {
            throw new RuntimeException($refreshed);
        }

        return null;
    }

    /**
     * Bring every live subscription into line with its vendor, and close the
     * ones whose paid time has run out. The safety net under webhooks.
     *
     * @return array{checked: int, ended: int, errors: int}
     */
    public function sync(): array
    {
        $counts = ['checked' => 0, 'ended' => $this->endTrials(), 'errors' => 0];

        Subscription::query()->live()->chunkById(100, function ($subscriptions) use (&$counts) {
            foreach ($subscriptions as $subscription) {
                $counts['checked']++;

                if (\is_string($error = $this->refresh($subscription))) {
                    $counts['errors']++;

                    Log::channel(LogChannelEnum::PAYMENTS->value)->warning('Subscription sync could not refresh a subscription', [
                        'subscription' => $subscription->id,
                        'vendor' => $subscription->vendor?->value,
                        'error' => $error,
                    ]);
                }

                if ($this->endIfOver($subscription)) {
                    $counts['ended']++;
                }
            }
        });

        return $counts;
    }

    // Tools

    /**
     * Write one renewal, once. The vendor's id for the charge is checked
     * against every payment already on the subscription — the first one
     * included, which PayPal also reports as a sale — and the charge is asked
     * about before anything is written.
     */
    protected function recordRenewal(Subscription $subscription, string $paymentId, mixed $periodEndsAt): ?string
    {
        if ($paymentId === '') {
            return 'The renewal carried no payment id to check.';
        }

        // The first payment has to be settled before a renewal can be told
        // apart from it; until then, the queue tries again later.
        $checkout = $subscription->transactions()->with('gateway')->oldest('id')->first();

        if ($checkout && $checkout->status->isProcessing()) {
            app(PaymentService::class)->verify($checkout);
            $checkout->refresh();

            if ($checkout->status->isProcessing()) {
                throw new RuntimeException('Waiting for the first payment of this subscription to be confirmed.');
            }
        }

        if ($this->hasPayment($subscription, $paymentId)) {
            return null;
        }

        $result = $subscription->vendor->gateway()->verifyRenewal($subscription, $paymentId);

        if (\is_string($result)) {
            throw new RuntimeException($result);
        }

        if (! $result['status']->isConfirmed()) {
            return 'The vendor does not report this renewal as paid.';
        }

        return DB::transaction(function () use ($subscription, $paymentId, $result, $periodEndsAt) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($this->hasPayment($locked, $paymentId)) {
                return null;
            }

            $expected = round((float) $locked->amount, 2);
            $received = $result['amount'] !== null ? round($result['amount'], 2) : null;

            $reason = match (true) {
                $received === null => 'The provider confirmed the renewal without an amount.',
                $received < $expected => 'Renewal paid '.kMoney($received).' against '.kMoney($expected).'.',
                $result['currency'] && strtoupper($locked->currency) !== $result['currency'] => "Renewal paid in {$result['currency']} against {$locked->currency}.",
                default => null,
            };

            $transaction = app(TransactionService::class)->record(
                $locked->user,
                TransactionTypeEnum::DIRECT,
                TransactionGroupEnum::SUBSCRIPTION,
                $received ?? $expected,
                "{$locked->plan?->name} renewal",
                TransactionViaEnum::PAYMENT_GATEWAY,
                TransactionWalletEnum::NONE,
                $reason ? StatusTransaction::PROCESSING : StatusTransaction::CONFIRMED,
                $locked,
                vendor: $locked->vendor,
            );

            $transaction->gateway->currency = $locked->currency;
            $transaction->gateway->gateway_request_id = $paymentId;
            $transaction->gateway->channel = $result['channel'];
            $transaction->gateway->paid_at = $result['paid_at'] ?? now();
            $transaction->gateway->verified_at = now();
            $transaction->gateway->failure_reason = $reason;
            $transaction->gateway->save();

            if ($reason) {
                PaymentNeedsReview::dispatch($transaction, $reason);

                return null;
            }

            $locked->status = $locked->cancel_at_period_end ? StatusSubscription::CANCELLED : StatusSubscription::ACTIVE;
            $locked->current_period_ends_at = $periodEndsAt ?? $locked->billing_interval->addTo(
                max(now(), $locked->current_period_ends_at ?? now())
            );
            $locked->save();

            SubscriptionRenewed::dispatch($locked, $transaction);

            return null;
        }, 3);
    }

    protected function hasPayment(Subscription $subscription, string $paymentId): bool
    {
        return TransactionGateway::query()
            ->where('vendor', $subscription->vendor)
            ->where('gateway_request_id', $paymentId)
            ->whereHas('transaction', fn ($query) => $query
                ->where('transactionable_type', $subscription->getMorphClass())
                ->where('transactionable_id', $subscription->id))
            ->exists();
    }

    /**
     * By the vendor's subscription id where the event carries one; otherwise
     * the customer's newest live-or-pending subscription with this vendor on
     * the same vendor plan, which is how a subscription the vendor started on
     * its own (Paystack, Flutterwave) is first linked.
     *
     * @param  array{vendor_subscription_id: ?string, customer_email: ?string, vendor_plan_id: ?string}  $event
     */
    protected function findForEvent(PaymentVendorEnum $vendor, array $event): ?Subscription
    {
        if ($event['vendor_subscription_id']) {
            $found = Subscription::query()
                ->where('vendor', $vendor)
                ->where('vendor_subscription_id', $event['vendor_subscription_id'])
                ->first();

            if ($found) {
                return $found;
            }
        }

        if (! $event['customer_email']) {
            return null;
        }

        $candidates = Subscription::query()
            ->where('vendor', $vendor)
            ->whereIn('status', [StatusSubscription::PENDING, StatusSubscription::ACTIVE, StatusSubscription::PAST_DUE, StatusSubscription::CANCELLED])
            ->whereHas('user', fn ($query) => $query->where('email', $event['customer_email']))
            ->when($event['vendor_plan_id'], fn ($query, $planId) => $query->where('vendor_plan_id', $planId))
            ->when($event['vendor_subscription_id'], fn ($query) => $query->whereNull('vendor_subscription_id'))
            ->newestFirst()
            ->get();

        // Without a plan id to narrow on, only an unambiguous match is used.
        if (! $event['vendor_plan_id'] && $candidates->count() > 1) {
            return null;
        }

        return $candidates->first();
    }

    /**
     * @param  array{status: StatusSubscription, vendor_subscription_id: ?string, period_ends_at: ?CarbonInterface, cancel_at_period_end: bool, token: ?string}  $state
     */
    protected function applyState(Subscription $subscription, array $state): void
    {
        $before = $subscription->status;

        // A subscription the kit is still waiting to see paid stays PENDING
        // until verify() starts it; the vendor calling it active first does not
        // skip the first payment's ledger row.
        $status = $before->isPending() && ! $state['status']->isExpired() ? $before : $state['status'];

        $subscription->status = $status;
        $subscription->vendor_subscription_id ??= $state['vendor_subscription_id'];
        $subscription->vendor_token = $state['token'] ?? $subscription->vendor_token;
        $subscription->cancel_at_period_end = $state['cancel_at_period_end'] || $status->isCancelled();
        $subscription->current_period_ends_at = $state['period_ends_at'] ?? $subscription->current_period_ends_at;

        if ($status->isCancelled() && ! $subscription->cancelled_at) {
            $subscription->cancelled_at = now();
        }

        $subscription->save();

        if ($status->isCancelled() && ! $before->isCancelled()) {
            SubscriptionCancelled::dispatch($subscription);
        }

        if ($status->isExpired() && ! $before->isExpired()) {
            $subscription->ended_at ??= now();
            $subscription->save();

            SubscriptionEnded::dispatch($subscription);
        }
    }

    /**
     * Trials past their end are over. They have no vendor to ask.
     */
    protected function endTrials(): int
    {
        $ended = 0;

        Subscription::query()->trialing()->where('current_period_ends_at', '<=', now())->chunkById(100, function ($trials) use (&$ended) {
            foreach ($trials as $trial) {
                $trial->status = StatusSubscription::EXPIRED;
                $trial->ended_at = now();
                $trial->save();

                SubscriptionEnded::dispatch($trial);
                $ended++;
            }
        });

        return $ended;
    }

    /**
     * A cancelled subscription whose paid time has run out is over.
     */
    protected function endIfOver(Subscription $subscription): bool
    {
        if (! $subscription->status->isCancelled() || $subscription->grantsAccess()) {
            return false;
        }

        $subscription->status = StatusSubscription::EXPIRED;
        $subscription->ended_at = now();
        $subscription->save();

        SubscriptionEnded::dispatch($subscription);

        return true;
    }
}
