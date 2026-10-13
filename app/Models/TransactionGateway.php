<?php

namespace App\Models;

use App\Enums\PaymentVendorEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class TransactionGateway extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'vendor' => PaymentVendorEnum::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    // Getters

    /**
     * A checkout link that has run out. The gateway will refuse it, so the page
     * offers a fresh attempt rather than sending somebody to a dead URL.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether a vendor stands behind this row, rather than an administrator
     * reviewing a bank transfer by hand.
     */
    public function isAutomated(): bool
    {
        return ! $this->vendor->isManual();
    }

    // Relationships

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
