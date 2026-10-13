<?php

namespace App\Models;

use App\Enums\SubscriptionFeatureEnum;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feature a plan grants. The row being there is the grant; feature_limit
 * only means something for a limit feature, where null is unlimited.
 */
#[Unguarded]
class SubscriptionPlanFeature extends Model
{
    protected function casts(): array
    {
        return [
            'feature' => SubscriptionFeatureEnum::class,
            'feature_limit' => 'integer',
        ];
    }

    // Getters

    public function displayLine(): string
    {
        return $this->feature->displayLine($this->feature_limit);
    }

    // Relationships

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
