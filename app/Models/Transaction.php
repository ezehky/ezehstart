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

    public function getRouteKeyName(): string
    {
        return 'reference';
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

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
