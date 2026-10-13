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
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class SubscriptionPlan extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'features' => 'array',
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

    public function vendorPlanFor(PaymentVendorEnum $vendor): ?SubscriptionPlanVendor
    {
        return $this->vendorPlans->firstWhere('vendor', $vendor);
    }

    public function label(): string
    {
        return $this->name;
    }

    // Relationships

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

    #[Scope]
    protected function cheapestFirst(Builder $query): void
    {
        $query->orderBy('amount')->orderBy('id');
    }
}
