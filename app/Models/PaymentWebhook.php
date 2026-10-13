<?php

namespace App\Models;

use App\Enums\PaymentVendorEnum;
use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusPaymentWebhook;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed call from a vendor, kept whether or not anything came of it.
 *
 * Only calls that passed signature verification are ever written, so every row
 * here is genuinely from the vendor it names.
 */
#[Unguarded]
class PaymentWebhook extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'vendor' => PaymentVendorEnum::class,
            'topic' => PaymentWebhookTopicEnum::class,
            'payload' => 'array',
            'status' => StatusPaymentWebhook::class,
            'processed_at' => 'datetime',
        ];
    }

    // Getters

    /**
     * A failed row can be put back on the queue. A processed or ignored one
     * cannot — working it again would be the duplicate the unique key exists to
     * prevent.
     */
    public function isRetryable(): bool
    {
        return $this->status->isFailed() || $this->status->isPending();
    }

    public function label(): string
    {
        return "{$this->vendor->label()} · ".($this->event_name ?: 'event');
    }

    // Scopes

    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', StatusPaymentWebhook::FAILED);
    }

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
