<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionChargeEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class TransactionCharge extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'charge_type' => TransactionChargeEnum::class,
            'amount' => MoneyCast::class,
        ];
    }

    // Relationships

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
