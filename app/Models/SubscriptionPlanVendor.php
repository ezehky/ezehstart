<?php

namespace App\Models;

use App\Enums\PaymentVendorEnum;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's copy on one vendor. Written the first time somebody subscribes
 * through that vendor; its plan id is emptied when the plan's price or
 * interval changes, and filled again by the next checkout.
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
}
