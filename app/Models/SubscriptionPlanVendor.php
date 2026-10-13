<?php

namespace App\Models;

use App\Enums\PaymentVendorEnum;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price's copy on one vendor. Written the first time somebody subscribes to
 * that price through that vendor; its plan id is emptied when the amount
 * changes, and filled again by the next checkout.
 *
 * The product a vendor hangs prices off (Stripe, PayPal) belongs to the plan,
 * so every price of one plan shares it.
 */
#[Unguarded]
class SubscriptionPlanVendor extends Model
{
    protected function casts(): array
    {
        return [
            'vendor' => PaymentVendorEnum::class,
        ];
    }

    // Relationships

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'subscription_plan_price_id');
    }
}
