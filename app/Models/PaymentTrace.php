<?php

namespace App\Models;

use App\Enums\PaymentTraceStepEnum;
use App\Enums\PaymentVendorEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a vendor, written only while payments.tracing.enabled is on.
 */
#[Unguarded]
class PaymentTrace extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'vendor' => PaymentVendorEnum::class,
            'trace_step' => PaymentTraceStepEnum::class,
            'succeeded' => 'boolean',
            'context' => 'array',
        ];
    }

    // Relationships

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // Scopes

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
