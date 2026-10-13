<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusDefault;
use App\Enums\SubscriptionIntervalEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One interval a plan is offered on, at its own amount. This — not the plan —
 * is what a vendor bills, so each one has its own vendor copies.
 */
#[Unguarded]
class SubscriptionPlanPrice extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'billing_interval' => SubscriptionIntervalEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * "₦5,000 / month", for a card or a table cell.
     */
    public function priceLabel(): string
    {
        return kMoneyFormat((float) $this->amount, decodeHtml: true).' / '.$this->billing_interval->perLabel();
    }

    /**
     * What this price saves against paying $base over the same length of time,
     * as a whole percentage — the yearly against twelve monthlies. Zero when it
     * saves nothing, so a price that costs more is never shown as a saving.
     */
    public function savingsAgainst(?self $base): int
    {
        if (! $base || $base->is($this) || (float) $base->amount <= 0) {
            return 0;
        }

        $perDay = (float) $this->amount / $this->billing_interval->averageDays();
        $basePerDay = (float) $base->amount / $base->billing_interval->averageDays();

        // Rounded down, so the saving is never overstated; the nudge only stops
        // float error turning an exact 2% into 1%.
        return max(0, (int) floor((1 - $perDay / $basePerDay) * 100 + 1e-9));
    }

    public function vendorPlanFor(PaymentVendorEnum $vendor): ?SubscriptionPlanVendor
    {
        return $this->vendorPlans->firstWhere('vendor', $vendor);
    }

    public function label(): string
    {
        return "{$this->plan?->name} · {$this->billing_interval->label()}";
    }

    // Relationships

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function vendorPlans(): HasMany
    {
        return $this->hasMany(SubscriptionPlanVendor::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }
}
