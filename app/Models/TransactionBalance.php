<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The balance either side of one movement, captured when it was applied.
 */
#[Unguarded]
class TransactionBalance extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'balance_before' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
        ];
    }

    // Getters

    /**
     * How much the balance actually moved. Derived rather than stored, so it can
     * never disagree with the two numbers it sits between.
     */
    public function delta(): float
    {
        return (float) $this->balance_after - (float) $this->balance_before;
    }

    // Relationships

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
