<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusSubscription;
use App\Enums\SubscriptionIntervalEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Unguarded]
class Subscription extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'vendor' => PaymentVendorEnum::class,
            'vendor_token' => 'encrypted',
            'amount' => MoneyCast::class,
            'billing_interval' => SubscriptionIntervalEnum::class,
            'status' => StatusSubscription::class,
            'current_period_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    // Getters

    /**
     * Whether the account should have what the plan gives right now: active,
     * behind on a renewal the vendor is still retrying, or cancelled with paid
     * time left. This is the check a project gates its features on.
     */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            StatusSubscription::ACTIVE, StatusSubscription::PAST_DUE => true,
            StatusSubscription::CANCELLED, StatusSubscription::TRIALING => $this->current_period_ends_at?->isFuture() ?? false,
            default => false,
        };
    }

    /**
     * Still something the vendor bills or might — not pending, not over.
     */
    public function isLive(): bool
    {
        return \in_array($this->status, [StatusSubscription::ACTIVE, StatusSubscription::PAST_DUE, StatusSubscription::CANCELLED], true)
            && ! ($this->status->isCancelled() && ! $this->grantsAccess());
    }

    public function canBeCancelled(): bool
    {
        return $this->status->isActive() || $this->status->isPastDue();
    }

    public function isTrial(): bool
    {
        return $this->status->isTrialing();
    }

    public function priceLabel(): string
    {
        if ($this->isTrial() || ! $this->billing_interval) {
            return 'Free trial';
        }

        return kMoneyFormat((float) $this->amount, decodeHtml: true).' / '.$this->billing_interval->perLabel();
    }

    public function label(): string
    {
        return "{$this->plan?->name} · {$this->user?->name}";
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'subscription_plan_price_id');
    }

    /**
     * The first payment and every renewal, as ledger rows.
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    // Scopes

    /**
     * Anything the vendor still has an opinion about — what the sync sweep asks
     * about.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', [StatusSubscription::ACTIVE, StatusSubscription::PAST_DUE, StatusSubscription::CANCELLED]);
    }

    /**
     * Anything that might give access: live, or a trial. What accessFor() reads.
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereIn('status', [StatusSubscription::ACTIVE, StatusSubscription::PAST_DUE, StatusSubscription::CANCELLED, StatusSubscription::TRIALING]);
    }

    #[Scope]
    protected function trialing(Builder $query): void
    {
        $query->where('status', StatusSubscription::TRIALING);
    }

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
