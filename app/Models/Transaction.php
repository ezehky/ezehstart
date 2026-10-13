<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Unguarded]
class Transaction extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionTypeEnum::class,
            'transaction_group' => TransactionGroupEnum::class,
            'transaction_wallet' => TransactionWalletEnum::class,
            'via' => TransactionViaEnum::class,
            'amount' => MoneyCast::class,
            'status' => StatusTransaction::class,
        ];
    }

    // Getters

    /**
     * The amount as it should read on a statement, signed by direction. A debit
     * shown without its minus is the single easiest way to make somebody think
     * they were charged twice.
     */
    public function signedAmount(): string
    {
        $prefix = $this->transaction_type->isDebit() ? '-' : '+';

        return $prefix.kMoneyFormat($this->amount, decodeHtml: true);
    }

    /**
     * Everything taken on top of the amount — fees and tax together.
     */
    public function totalCharges(): float
    {
        return (float) $this->charges->sum('amount');
    }

    /**
     * What actually left or reached the account once charges are applied.
     */
    public function netAmount(): float
    {
        return $this->transaction_type->isDebit()
            ? (float) $this->amount + $this->totalCharges()
            : (float) $this->amount - $this->totalCharges();
    }

    /**
     * A finished transaction is one nothing further will happen to. Used to decide
     * whether an admin action or a gateway callback is still allowed to change it.
     */
    public function isSettled(): bool
    {
        return \in_array($this->status, [
            StatusTransaction::CONFIRMED,
            StatusTransaction::FAILED,
            StatusTransaction::CANCELLED,
            StatusTransaction::REFUNDED,
            StatusTransaction::REJECTED,
        ], true);
    }

    /**
     * What has gone back, or is on its way back, against this payment. A
     * failed refund returned nothing, so it does not count.
     *
     * Reads refunded_minor when the query selected it with withRefundedSum(),
     * so a listing does not ask once per row.
     */
    public function refundedAmount(): float
    {
        if (\array_key_exists('refunded_minor', $this->attributes)) {
            return (int) $this->attributes['refunded_minor'] / 100;
        }

        return (int) $this->refunds()
            ->whereIn('status', [StatusTransaction::CONFIRMED, StatusTransaction::PROCESSING])
            ->sum('amount') / 100;
    }

    public function refundableAmount(): float
    {
        return max(0, round((float) $this->amount - $this->refundedAmount(), 2));
    }

    /**
     * Money that came in from outside and can be sent back the way it came: a
     * confirmed gateway payment or an approved bank transfer, not already
     * refunded in full. Balance spends and platform rows have nowhere outside
     * to go back to — those are an adjustment.
     */
    public function isRefundable(): bool
    {
        return $this->status->isConfirmed()
            && ! $this->transaction_group->isRefund()
            && ! $this->transaction_type->isDebit()
            && ($this->via->isPaymentGateway() || $this->via->isBankTransfer())
            && $this->refundableAmount() > 0;
    }

    public function label(): string
    {
        return $this->reference;
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function gateway(): HasOne
    {
        return $this->hasOne(TransactionGateway::class);
    }

    public function meta(): HasOne
    {
        return $this->hasOne(TransactionMeta::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TransactionEvidence::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(TransactionBalance::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(TransactionCharge::class);
    }

    /**
     * The refunds written against this payment. A refund is its own REFUND row
     * whose transactionable is the payment, so a partial refund, a second one,
     * and a failed attempt are each on the statement in their own right.
     */
    public function refunds(): MorphMany
    {
        return $this->morphMany(self::class, 'transactionable')
            ->where('transaction_group', TransactionGroupEnum::REFUND);
    }

    /**
     * Every call made to a vendor about this payment, while tracing is on.
     */
    public function traces(): HasMany
    {
        return $this->hasMany(PaymentTrace::class);
    }

    // Scopes

    #[Scope]
    protected function confirmed(Builder $query): void
    {
        $query->where('status', StatusTransaction::CONFIRMED);
    }

    /**
     * Waiting on somebody: queued for review, or out with a gateway. This is the
     * admin's work list.
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereIn('status', [StatusTransaction::QUEUED, StatusTransaction::PROCESSING]);
    }

    #[Scope]
    protected function ofGroup(Builder $query, TransactionGroupEnum $group): void
    {
        $query->where('transaction_group', $group);
    }

    /**
     * Select what has been refunded against each row, in minor units, in the
     * same query — for refundedAmount() on a listing.
     */
    #[Scope]
    protected function withRefundedSum(Builder $query): void
    {
        $query->withSum(['refunds as refunded_minor' => fn ($refunds) => $refunds
            ->whereIn('status', [StatusTransaction::CONFIRMED, StatusTransaction::PROCESSING])], 'amount');
    }

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
