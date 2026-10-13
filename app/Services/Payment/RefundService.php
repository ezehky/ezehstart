<?php

namespace App\Services\Payment;

use App\Enums\ActivityActionEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Events\PaymentNeedsReview;
use App\Events\RefundFailed;
use App\Events\RefundInitiated;
use App\Events\RefundProcessed;
use App\Models\PaymentWebhook;
use App\Models\Transaction;
use App\Services\ActivityLogService;
use App\Services\TransactionService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sending money back.
 *
 * A refund is its own REFUND row against the payment — never an edit of the
 * payment. The payment stays CONFIRMED, because it did happen; the refund row
 * says what went back, and a deposit's refund is a DEBIT so the balance falls
 * by exactly the amount returned. Partial and repeated refunds are just more
 * rows, and what is left to refund is the payment less every row that is
 * confirmed or still on its way.
 *
 * Two kinds of payment can be refunded:
 *
 * - A gateway payment, sent back through its vendor. The row is PROCESSING
 *   until the vendor says it is done — at once for some, by webhook or the
 *   reconcile sweep for others.
 * - A bank transfer an administrator approved. There is no vendor to ask, so
 *   the administrator pays it back by hand and the row is recorded CONFIRMED.
 */
#[Singleton]
class RefundService
{
    // Getters

    /**
     * Why this refund cannot go ahead, or null when it can.
     */
    public function refundBlockedReason(Transaction $payment, float $amount): ?string
    {
        $payment->loadMissing('gateway');

        if (! $payment->isRefundable()) {
            return match (true) {
                ! $payment->status->isConfirmed() => 'Only a confirmed payment can be refunded.',
                $payment->transaction_group->isRefund() => 'A refund cannot itself be refunded.',
                $payment->refundableAmount() <= 0 => 'This payment has already been refunded in full.',
                default => 'Only money paid in through a gateway or a bank transfer can be refunded. Use an adjustment instead.',
            };
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            return 'The refund must be more than zero.';
        }

        if ($amount > $payment->refundableAmount()) {
            return 'That is more than is left to refund ('.kMoney($payment->refundableAmount()).').';
        }

        // A deposit's refund comes out of the balance it went into. If the member
        // has already spent it, sending it back as well would pay them twice.
        if ($payment->transaction_wallet->isBalance() && app(TransactionService::class)->balanceFor($payment->user) < $amount) {
            return 'The account balance is lower than the refund. The member has already spent part of this deposit.';
        }

        $vendor = $payment->gateway?->vendor ?? PaymentVendorEnum::MANUAL;

        if (! $vendor->isManual() && ! $vendor->isConfigured()) {
            return "{$vendor->label()} is not configured, so the refund cannot be sent.";
        }

        return null;
    }

    // Actions

    /**
     * Refund part or all of a payment. Returns the reason it could not be done,
     * or the REFUND row.
     */
    public function refund(Transaction $payment, float $amount, string $reason): string|Transaction
    {
        $amount = round($amount, 2);

        // The check and the write happen under one lock on the payment, so two
        // administrators refunding at once cannot between them send back more
        // than came in. The PROCESSING row counts against the next check the
        // moment it is written.
        $refund = DB::transaction(function () use ($payment, $amount, $reason) {
            $locked = Transaction::query()->with('gateway')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($blocked = $this->refundBlockedReason($locked, $amount)) {
                return $blocked;
            }

            $vendor = $locked->gateway?->vendor ?? PaymentVendorEnum::MANUAL;
            $toBalance = $locked->transaction_wallet->isBalance();

            $refund = app(TransactionService::class)->record(
                $locked->user,
                $toBalance ? TransactionTypeEnum::DEBIT : TransactionTypeEnum::DIRECT,
                TransactionGroupEnum::REFUND,
                $amount,
                "Refund of {$locked->reference}",
                $locked->via,
                $locked->transaction_wallet,
                StatusTransaction::PROCESSING,
                $locked,
                ['reason' => $reason, 'refunded_by' => auth()->id()],
                vendor: $vendor,
            );

            $refund->gateway->currency = $locked->gateway?->currency ?? strtoupper(kDefaultCurrency()['code']);
            $refund->gateway->save();

            return $refund;
        }, 3);

        if (\is_string($refund)) {
            return $refund;
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::TRANSACTION_REFUND,
            ' '.kMoney($amount)." of {$payment->reference} as {$refund->reference} — {$reason}",
            model: $refund,
        );

        // Paid in by hand, paid back by hand: the administrator recording it is
        // the confirmation.
        if ($refund->gateway->vendor->isManual()) {
            $this->apply($refund, StatusTransaction::CONFIRMED, 'Paid back by bank transfer.');

            return $refund->fresh(['gateway']);
        }

        $payment->loadMissing('gateway');

        $result = $refund->gateway->vendor->gateway()->refund($payment->gateway, $refund, $amount, $reason);

        if (\is_string($result)) {
            $this->apply($refund, StatusTransaction::FAILED, $result);

            return $result;
        }

        $refund->gateway->gateway_id = $result['refund_id'] ?? $refund->gateway->gateway_id;
        $refund->gateway->save();

        $this->apply($refund, $result['status'], $result['message'], initiated: true);

        return $refund->fresh(['gateway']);
    }

    /**
     * Ask the vendor where a PROCESSING refund has got to, and settle it if the
     * answer is final.
     */
    public function verifyRefund(Transaction $refund): string|Transaction
    {
        $refund->loadMissing('gateway');

        if (! $refund->transaction_group->isRefund() || ! $refund->gateway) {
            return 'This is not a refund.';
        }

        if ($refund->isSettled() || $refund->gateway->vendor->isManual()) {
            return $refund;
        }

        if (! $refund->gateway->vendor->isConfigured()) {
            return "{$refund->gateway->vendor->label()} is not configured.";
        }

        $result = $refund->gateway->vendor->gateway()->refundStatus($refund->gateway);

        if (\is_string($result)) {
            return $result;
        }

        return DB::transaction(function () use ($refund, $result) {
            $locked = Transaction::query()->with('gateway')->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isSettled()) {
                $locked->gateway->gateway_id ??= $result['refund_id'];
                $locked->gateway->verified_at = now();
                $locked->gateway->save();

                $this->apply($locked, $result['status'], $result['message']);
            }

            return $locked->fresh(['gateway']);
        }, 3);
    }

    /**
     * Work a refund webhook. Null when it was acted on; otherwise why it was
     * not. Throws when the vendor could not be asked, so the queue retries.
     */
    public function handleWebhook(PaymentWebhook $webhook): ?string
    {
        $found = app(PaymentService::class)->findForWebhook($webhook);

        if (! $found) {
            return 'No transaction matches this webhook.';
        }

        // Some vendors name the refund itself; the rest name the payment, and
        // every refund still on its way against it is asked about.
        $pending = $found->transaction_group->isRefund()
            ? collect([$found])
            : $found->refunds()->with('gateway')->where('status', StatusTransaction::PROCESSING)->get();

        if ($pending->isEmpty()) {
            // The vendor refunded a payment the ledger never asked it to — from
            // the vendor's own dashboard, most likely. The money is gone and the
            // balance does not know, which is a person's decision to make.
            if (! $found->transaction_group->isRefund() && ! $found->refunds()->exists()) {
                PaymentNeedsReview::dispatch($found, "{$webhook->vendor->label()} reports a refund that was not made through this site.");
            }

            return 'No refund is waiting on this webhook.';
        }

        foreach ($pending as $refund) {
            $result = $this->verifyRefund($refund);

            if (\is_string($result)) {
                throw new RuntimeException($result);
            }
        }

        return null;
    }

    // Tools

    /**
     * Move a refund row to where the vendor says it is, and tell listeners.
     */
    protected function apply(Transaction $refund, StatusTransaction $status, ?string $message, bool $initiated = false): void
    {
        if ($status->isConfirmed()) {
            $refund->gateway->paid_at ??= now();
            $refund->gateway->failure_reason = null;
            $refund->gateway->save();

            app(TransactionService::class)->settle($refund, StatusTransaction::CONFIRMED, $message);

            RefundProcessed::dispatch($refund);

            return;
        }

        if ($status->isFailed() || $status->isCancelled()) {
            $refund->gateway->failure_reason = $message ? str()->limit($message, 250) : null;
            $refund->gateway->save();

            app(TransactionService::class)->settle($refund, StatusTransaction::FAILED, $message);

            RefundFailed::dispatch($refund, $message);

            return;
        }

        if ($initiated) {
            RefundInitiated::dispatch($refund);
        }
    }
}
