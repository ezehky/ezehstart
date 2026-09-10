<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusTransaction;
use App\Enums\TransactionChargeEnum;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ledger.
 *
 * Every movement of money goes through here, so there is exactly one place that
 * knows how a balance is derived and one place that writes the audit trail for it.
 */
#[Singleton]
class TransactionService
{
    /**
     * A user's balance, in major units.
     *
     * Derived from confirmed rows rather than stored on the user, so a balance can
     * never silently disagree with the ledger that is supposed to explain it. The
     * sums are raw, so they come back in minor units and are divided once here.
     */
    public function balanceFor(User $user): float
    {
        $base = Transaction::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->where('transaction_wallet', TransactionWalletEnum::BALANCE);

        $credits = (int) (clone $base)->where('transaction_type', TransactionTypeEnum::CREDIT)->sum('amount');
        $debits = (int) (clone $base)->where('transaction_type', TransactionTypeEnum::DEBIT)->sum('amount');

        return ($credits - $debits) / 100;
    }

    /**
     * Write a transaction, and its balance snapshot when it moves a wallet.
     *
     * The user row is locked for the duration: two requests spending the same
     * balance at the same time would otherwise both read it before either wrote,
     * and both would be allowed through.
     *
     * @param  array<string, mixed>  $meta  Gateway payload, stored verbatim.
     * @param  array<string, float>  $charges  Keyed by TransactionChargeEnum value.
     */
    public function record(
        User $user,
        TransactionTypeEnum $type,
        TransactionGroupEnum $group,
        float $amount,
        string $description,
        TransactionViaEnum $via = TransactionViaEnum::PLATFORM,
        TransactionWalletEnum $wallet = TransactionWalletEnum::BALANCE,
        StatusTransaction $status = StatusTransaction::CONFIRMED,
        ?Model $against = null,
        array $meta = [],
        array $charges = [],
        ?PaymentVendorEnum $vendor = null,
    ): Transaction {
        return DB::transaction(function () use (
            $user, $type, $group, $amount, $description, $via, $wallet, $status, $against, $meta, $charges, $vendor
        ) {
            // Lock the account before reading its balance, so a concurrent spend
            // cannot slip between the read and the write.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $before = $this->balanceFor($locked);

            $transaction = Transaction::query()->create([
                'user_id' => $locked->id,
                'reference' => kReferenceId('TXN-'),
                'transaction_type' => $type,
                'transaction_group' => $group,
                'transaction_wallet' => $wallet,
                'amount' => $amount,
                'description' => $description,
                'via' => $via,
                'transactionable_type' => $against?->getMorphClass(),
                'transactionable_id' => $against?->getKey(),
                'status' => $status,
            ]);

            // Only a confirmed movement against a wallet changes a balance, so
            // only that one gets a snapshot. A queued transfer has not moved
            // anything yet and must not look as though it has.
            if ($status->isConfirmed() && $wallet->isBalance()) {
                $delta = $type->isDebit() ? -$amount : $amount;

                $transaction->balance()->create([
                    'balance_before' => $before,
                    'balance_after' => $before + $delta,
                ]);
            }

            foreach ($charges as $chargeType => $chargeAmount) {
                if ($chargeAmount <= 0) {
                    continue;
                }

                $transaction->charges()->create([
                    'charge_type' => TransactionChargeEnum::from($chargeType),
                    'amount' => $chargeAmount,
                ]);
            }

            if ($meta) {
                $transaction->meta()->create(['content' => $meta]);
            }

            if ($vendor) {
                $transaction->gateway()->create(['vendor' => $vendor]);
            }

            return $transaction;
        }, 3);
    }

    /**
     * Move a pending transaction to a final state.
     *
     * Returns the reason it was refused, or null when it worked. A settled row is
     * never re-settled: a webhook that arrives twice must be a no-op, not a
     * second credit.
     */
    public function settle(Transaction $transaction, StatusTransaction $status, ?string $note = null): ?string
    {
        if ($transaction->isSettled()) {
            return 'This transaction has already been settled and cannot be changed.';
        }

        return DB::transaction(function () use ($transaction, $status, $note) {
            User::query()->whereKey($transaction->user_id)->lockForUpdate()->first();

            $before = $this->balanceFor($transaction->user);

            $transaction->status = $status;
            $transaction->save();

            // The snapshot is written on confirmation, because that is the moment
            // the money actually moved.
            if ($status->isConfirmed() && $transaction->transaction_wallet->isBalance() && ! $transaction->balance) {
                $delta = $transaction->transaction_type->isDebit()
                    ? -(float) $transaction->amount
                    : (float) $transaction->amount;

                $transaction->balance()->create([
                    'balance_before' => $before,
                    'balance_after' => $before + $delta,
                ]);
            }

            $action = match (true) {
                $status->isConfirmed() => ActivityActionEnum::TRANSACTION_CONFIRM,
                $status->isRefunded() => ActivityActionEnum::TRANSACTION_REFUND,
                default => ActivityActionEnum::TRANSACTION_REJECT,
            };

            app(ActivityLogService::class)->logActivity(
                $action,
                " transaction: {$transaction->reference}".($note ? " — {$note}" : ''),
                model: $transaction,
            );

            return null;
        }, 3);
    }

    /**
     * An administrator moving money by hand. Always CONFIRMED, always logged, and
     * always against the ADJUSTMENT group so it can be found again.
     */
    public function adjust(User $user, float $amount, string $reason, bool $credit = true): Transaction
    {
        $transaction = $this->record(
            $user,
            $credit ? TransactionTypeEnum::CREDIT : TransactionTypeEnum::DEBIT,
            TransactionGroupEnum::ADJUSTMENT,
            $amount,
            $reason,
            TransactionViaEnum::PLATFORM,
        );

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::TRANSACTION_CREATE,
            " adjustment for {$user->name}: {$reason}",
            model: $transaction,
        );

        return $transaction;
    }
}
