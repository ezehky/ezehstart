<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Enums\SubscriptionFeatureEnum;
use App\Enums\SubscriptionIntervalEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a plan is — its name, what it grants, whether it is free, recommended
 * or comes with a trial. What it costs is its prices, one for each interval it
 * is offered on.
 */
#[Unguarded]
class SubscriptionPlan extends Model
{
    use WithDynamicModelFormatting;

    /**
     * The column defaults, so a plan created without them reads the same as
     * one loaded back from the table.
     */
    protected $attributes = [
        'is_free' => 0,
        'is_recommended' => 0,
        'trial_days' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_free' => StatusYes::class,
            'is_recommended' => StatusYes::class,
            'trial_days' => 'integer',
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * The prices still offered, shortest interval first.
     *
     * @return Collection<int, SubscriptionPlanPrice>
     */
    public function activePrices(): Collection
    {
        return $this->prices
            ->filter(fn (SubscriptionPlanPrice $price) => $price->status->isActive())
            ->sortBy(fn (SubscriptionPlanPrice $price) => $price->billing_interval->averageDays())
            ->values();
    }

    public function priceFor(SubscriptionIntervalEnum $interval): ?SubscriptionPlanPrice
    {
        return $this->activePrices()->first(fn (SubscriptionPlanPrice $price) => $price->billing_interval === $interval);
    }

    /**
     * What a longer interval's saving is worked out against: the shortest
     * interval the plan is offered on.
     */
    public function basePrice(): ?SubscriptionPlanPrice
    {
        return $this->activePrices()->first();
    }

    /**
     * The plans this one builds on, nearest first: Pro includes Team includes
     * Basic gives [Team, Basic]. The admin form refuses a loop; the depth cap
     * is there for one written some other way.
     *
     * @return array<int, SubscriptionPlan>
     */
    public function includedChain(): array
    {
        $chain = [];
        $seen = [$this->id];
        $plan = $this->includedPlan;

        while ($plan && ! \in_array($plan->id, $seen, true) && \count($chain) < 10) {
            $chain[] = $plan;
            $seen[] = $plan->id;
            $plan = $plan->includedPlan;
        }

        return $chain;
    }

    /**
     * Everything the plan grants: its own rows, and every row of the plans it
     * includes. Where both grant the same limit, the more generous one wins —
     * an upgrade never takes away.
     *
     * @return array<string, ?int> feature value => limit (null is unlimited, or not a limit)
     */
    public function grantedFeatures(): array
    {
        $granted = [];

        foreach ([$this, ...$this->includedChain()] as $plan) {
            foreach ($plan->features as $row) {
                $key = $row->feature->value;

                $granted[$key] = \array_key_exists($key, $granted)
                    ? self::moreGenerous($granted[$key], $row->feature_limit)
                    : $row->feature_limit;
            }
        }

        return $granted;
    }

    public function grants(SubscriptionFeatureEnum $feature): bool
    {
        return \array_key_exists($feature->value, $this->grantedFeatures());
    }

    /**
     * How many of a limit feature the plan allows: null for unlimited, 0 when
     * it does not grant it at all.
     */
    public function limitOf(SubscriptionFeatureEnum $feature): ?int
    {
        $granted = $this->grantedFeatures();

        return \array_key_exists($feature->value, $granted) ? $granted[$feature->value] : 0;
    }

    /**
     * The lines a pricing card lists. With an included plan, only what this
     * plan adds or raises — the card says "Everything in Basic, plus" above
     * them — in the order the enum lists features, so every card reads the
     * same way down.
     *
     * @return array<int, string>
     */
    public function featureLines(): array
    {
        $inherited = $this->includedPlan?->grantedFeatures() ?? [];
        $lines = [];

        foreach (SubscriptionFeatureEnum::cases() as $feature) {
            $row = $this->features->first(fn (SubscriptionPlanFeature $row) => $row->feature === $feature);

            if (! $row) {
                continue;
            }

            $alreadyHad = \array_key_exists($feature->value, $inherited)
                && (! $feature->isLimit() || self::moreGenerous($inherited[$feature->value], $row->feature_limit) === $inherited[$feature->value]);

            if (! $alreadyHad) {
                $lines[] = $row->displayLine();
            }
        }

        return $lines;
    }

    public function hasTrial(): bool
    {
        return ! $this->is_free->isYes() && $this->trial_days > 0;
    }

    public function label(): string
    {
        return $this->name;
    }

    // Relationships

    public function includedPlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'includes_plan_id');
    }

    /**
     * The plans that build on this one.
     */
    public function includingPlans(): HasMany
    {
        return $this->hasMany(self::class, 'includes_plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(SubscriptionPlanFeature::class);
    }

    public function vendorPlans(): HasMany
    {
        return $this->hasMany(SubscriptionPlanVendor::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Tools

    /**
     * Null is unlimited, so it beats any number.
     */
    protected static function moreGenerous(?int $a, ?int $b): ?int
    {
        return $a === null || $b === null ? null : max($a, $b);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    #[Scope]
    protected function free(Builder $query): void
    {
        $query->where('is_free', StatusYes::YES);
    }

    /**
     * The free plan, then the rest by the lowest price each is offered at.
     */
    #[Scope]
    protected function cheapestFirst(Builder $query): void
    {
        $query->withMin(['prices as lowest_amount' => fn (Builder $prices) => $prices->active()], 'amount')
            ->orderByDesc('is_free')
            ->orderBy('lowest_amount')
            ->orderBy('id');
    }
}
