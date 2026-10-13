<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusSubscription;
use App\Enums\StatusYes;
use App\Enums\SubscriptionFeatureEnum;
use App\Enums\SubscriptionIntervalEnum;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Services\ActivityLogService;
use App\Services\Payment\SubscriptionService;
use App\Traits\WithGateProps;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithGateProps, WithPagination;

    #[Url]
    public string $tab = 'plans';

    #[Url]
    public string $subscriptionStatus = '';

    // Plan form
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $is_free = false;

    public bool $is_recommended = false;

    public string $trial_days = '';

    public string $includes_plan_id = '';

    public bool $active = true;

    /** @var array<string, mixed> interval value => amount, empty when not offered */
    public array $prices = [];

    /** @var array<string, bool> feature value => granted */
    public array $featureOn = [];

    /** @var array<string, string> feature value => limit, empty for unlimited */
    public array $featureLimits = [];

    /** The plan or subscription a confirm dialog is about. */
    public ?int $targetId = null;

    public function mount(): void
    {
        kSetSiteTitle('subscriptions');
        $this->setPageGate('subscriptions');

        if (! \in_array($this->tab, ['plans', 'subscribers'], true)) {
            $this->tab = 'plans';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedSubscriptionStatus(): void
    {
        $this->resetPage();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::query()
            ->withCount(['subscriptions as live_count' => fn (Builder $query) => $query->current()])
            ->with(['prices', 'vendorPlans', 'includedPlan'])
            ->cheapestFirst()
            ->get();
    }

    /**
     * The plans this one may build on: any other plan, short of one that
     * already builds on this one — that would be a loop.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    #[Computed]
    public function includableOptions(): Collection
    {
        return $this->plans
            ->reject(fn (SubscriptionPlan $plan) => $this->editingId !== null && (
                $plan->id === $this->editingId
                || collect($plan->includedChain())->contains('id', $this->editingId)
            ))
            ->values();
    }

    #[Computed]
    public function subscriptions()
    {
        return Subscription::query()
            ->with(['user', 'plan'])
            ->when($this->subscriptionStatus !== '', fn (Builder $query) => $query->where('status', (int) $this->subscriptionStatus))
            ->newestFirst()
            ->paginate(20);
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // PLANS

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_free' => ['boolean'],
            'is_recommended' => ['boolean'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'includes_plan_id' => ['nullable', Rule::in($this->includableOptions->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'active' => ['boolean'],
            'prices' => ['array'],
            'prices.*' => ['nullable', 'numeric', 'min:1'],
            'featureOn' => ['array'],
            'featureOn.*' => ['boolean'],
            'featureLimits' => ['array'],
            'featureLimits.*' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'includes_plan_id' => 'included plan',
            'prices.*' => 'price',
            'featureLimits.*' => 'limit',
        ];
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetValidation();
        $this->reset('editingId', 'name', 'description', 'is_free', 'is_recommended', 'trial_days', 'includes_plan_id', 'prices', 'featureOn', 'featureLimits');
        $this->prices = array_fill_keys(SubscriptionIntervalEnum::values(), null);
        $this->active = true;
        unset($this->includableOptions);

        Flux::modal('planModal')->show();
    }

    public function edit(int $planId): void
    {
        $this->checkGate();

        $plan = SubscriptionPlan::query()->with(['prices', 'features'])->findOrFail($planId);

        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->is_free = $plan->is_free->isYes();
        $this->is_recommended = $plan->is_recommended->isYes();
        $this->trial_days = $plan->trial_days ? (string) $plan->trial_days : '';
        $this->includes_plan_id = (string) $plan->includes_plan_id;
        $this->active = $plan->status->isActive();

        // Every interval gets a key, so each price box is entangled to something.
        $this->prices = array_merge(
            array_fill_keys(SubscriptionIntervalEnum::values(), null),
            $plan->activePrices()
                ->mapWithKeys(fn (SubscriptionPlanPrice $price) => [$price->billing_interval->value => (float) $price->amount])
                ->all(),
        );

        $this->featureOn = [];
        $this->featureLimits = [];

        foreach ($plan->features as $row) {
            $this->featureOn[$row->feature->value] = true;
            $this->featureLimits[$row->feature->value] = $row->feature_limit !== null ? (string) $row->feature_limit : '';
        }

        unset($this->includableOptions);

        Flux::modal('planModal')->show();
    }

    /**
     * A plan's prices are one row an interval. An emptied interval is switched
     * off rather than deleted, because subscribers on it point at it; a changed
     * amount forgets that price's vendor copies, so the next subscriber is
     * billed the new amount and existing ones keep what they signed up to.
     */
    public function save(): bool
    {
        $this->checkGate($this->editingId === null ? GateAccessEnum::CREATE : GateAccessEnum::MODIFY);

        $this->validate();

        $amounts = collect(SubscriptionIntervalEnum::cases())
            ->mapWithKeys(fn (SubscriptionIntervalEnum $interval) => [$interval->value => $this->prices[$interval->value] ?? null])
            ->filter(fn ($amount) => filled($amount) && (float) $amount > 0)
            ->map(fn ($amount) => (float) $amount);

        $this->respondError('Give the plan a price for at least one interval, or mark it free.', ! $this->is_free && $amounts->isEmpty());

        $plan = $this->editingId ? SubscriptionPlan::query()->with(['prices', 'features'])->findOrFail($this->editingId) : new SubscriptionPlan;
        $isNew = ! $plan->exists;

        $plan->name = $this->name;
        $plan->slug = $isNew ? $this->uniqueSlug($this->name) : $plan->slug;
        $plan->description = $this->description ?: null;
        $plan->is_free = $this->is_free ? StatusYes::YES : StatusYes::NO;
        $plan->is_recommended = $this->is_recommended ? StatusYes::YES : StatusYes::NO;
        // A free plan is what everybody has already; there is nothing to try.
        $plan->trial_days = $this->is_free ? 0 : (int) $this->trial_days;
        $plan->includes_plan_id = $this->includes_plan_id !== '' ? (int) $this->includes_plan_id : null;
        $plan->status = $this->active ? StatusDefault::ACTIVE : StatusDefault::INACTIVE;

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($plan);

        DB::transaction(function () use ($plan, $amounts) {
            $plan->save();

            // One free plan and one recommended plan at a time: the free plan is
            // what an account without a subscription has, and two of either
            // could not both be what the label says.
            if ($plan->is_free->isYes()) {
                SubscriptionPlan::query()->whereKeyNot($plan->id)->update(['is_free' => StatusYes::NO]);
            }

            if ($plan->is_recommended->isYes()) {
                SubscriptionPlan::query()->whereKeyNot($plan->id)->update(['is_recommended' => StatusYes::NO]);
            }

            $this->savePrices($plan, $this->is_free ? collect() : $amounts);
            $this->saveFeatures($plan);
        });

        $serviceInstance->logActivity(
            $isNew ? ActivityActionEnum::CREATE : ActivityActionEnum::UPDATE,
            " subscription plan: {$plan->name}",
            $affectedColumns,
            model: $plan,
        );

        Flux::modal('planModal')->close();
        unset($this->plans, $this->includableOptions);

        return $this->respondSuccess($isNew ? 'Plan created.' : 'Plan updated.');
    }

    public function confirmDeletePlan(int $planId): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to subscription plans.');

        $this->targetId = $planId;

        Flux::modal('deletePlanModal')->show();
    }

    public function deletePlan(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $plan = SubscriptionPlan::query()->whereKey($this->targetId)->first();

        abort_unless((bool) $plan, 404);

        $this->respondError(
            'Somebody has subscribed to this plan, so it stays on file. Switch it off instead.',
            $plan->subscriptions()->exists(),
        );

        $this->respondError(
            'Another plan includes everything in this one. Change that plan first.',
            $plan->includingPlans()->exists(),
        );

        $plan->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::DELETE, " subscription plan: {$plan->name}", model: $plan);

        $this->reset('targetId');
        unset($this->plans);

        return $this->respondSuccess('Plan deleted.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // SUBSCRIBERS

    public function refreshSubscription(int $subscriptionId): bool
    {
        $this->checkGate();

        $subscription = Subscription::query()->findOrFail($subscriptionId);

        $this->respondError('A trial has no payment provider to ask.', $subscription->vendor === null);

        $result = app(SubscriptionService::class)->refresh($subscription);

        unset($this->subscriptions);

        $this->respondError($result, \is_string($result));

        return $this->respondSuccess("{$subscription->vendor->label()} reports it as ".$result->status->label(true).'.');
    }

    public function confirmCancel(int $subscriptionId): void
    {
        $this->checkGate();

        $this->targetId = $subscriptionId;

        Flux::modal('cancelSubscriptionModal')->show();
    }

    public function cancelSubscription(): bool
    {
        $this->checkGate();

        $subscription = Subscription::query()->with(['user', 'plan'])->whereKey($this->targetId)->first();

        abort_unless((bool) $subscription, 404);

        $error = app(SubscriptionService::class)->cancel($subscription);

        $this->respondError($error, $error !== null);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::UPDATE,
            " subscription: cancelled {$subscription->label()}",
            model: $subscription,
        );

        $this->reset('targetId');
        unset($this->subscriptions);

        return $this->respondSuccess('The subscription will not renew.');
    }

    // Tools

    /**
     * @param  Collection<string, float>  $amounts  interval value => amount
     */
    protected function savePrices(SubscriptionPlan $plan, Collection $amounts): void
    {
        $existing = $plan->prices()->get()->keyBy(fn (SubscriptionPlanPrice $price) => $price->billing_interval->value);
        $currency = strtoupper(kDefaultCurrency()['code']);

        foreach (SubscriptionIntervalEnum::cases() as $interval) {
            $price = $existing->get($interval->value);
            $amount = $amounts->get($interval->value);

            if ($amount === null) {
                if ($price?->status->isActive()) {
                    $price->status = StatusDefault::INACTIVE;
                    $price->save();
                }

                continue;
            }

            $price ??= new SubscriptionPlanPrice(['subscription_plan_id' => $plan->id, 'billing_interval' => $interval]);
            $price->amount = $amount;
            $price->currency = $price->currency ?: $currency;
            $price->status = StatusDefault::ACTIVE;

            $amountChanged = $price->exists && $price->isDirty(['amount', 'currency']);

            $price->save();

            if ($amountChanged) {
                app(SubscriptionService::class)->priceChanged($price);
            }
        }
    }

    protected function saveFeatures(SubscriptionPlan $plan): void
    {
        $granted = collect(SubscriptionFeatureEnum::cases())
            ->filter(fn (SubscriptionFeatureEnum $feature) => (bool) ($this->featureOn[$feature->value] ?? false));

        $plan->features()->whereNotIn('feature', $granted->map(fn (SubscriptionFeatureEnum $feature) => $feature->value)->all())->delete();

        foreach ($granted as $feature) {
            $limit = $this->featureLimits[$feature->value] ?? '';

            $plan->features()->updateOrCreate(
                ['feature' => $feature],
                ['feature_limit' => $feature->isLimit() && $limit !== '' ? (int) $limit : null],
            );
        }
    }

    protected function uniqueSlug(string $name): string
    {
        $base = kSlug($name) ?: 'plan';
        $slug = $base;
        $suffix = 2;

        while (SubscriptionPlan::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Subscriptions</flux:heading>
                <flux:text class="mt-1">Plans members can subscribe to, and who is on them. The payment provider bills each renewal.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <flux:radio.group wire:model.live="tab" variant="segmented">
                    <flux:radio value="plans" label="Plans" />
                    <flux:radio value="subscribers" label="Subscribers" />
                </flux:radio.group>

                @if ($tab === 'plans')
                    <x-dashboard.gate.button :gate="$pageGate" :level="$gateCreate" variant="primary" icon="plus" wire:click="create">
                        New plan
                    </x-dashboard.gate.button>
                @endif
            </div>
        </div>

        {{-- ||||||||||||||||||||||||||||||||||||||||||||||||
             PLANS --}}
        @if ($tab === 'plans')
            @if ($this->plans->isEmpty())
                <x-dashboard.workspace-no-record
                    icon="sparkles"
                    label="No plans yet"
                    text="Create a plan, and members can subscribe to it from their workspace."
                />
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Plan</flux:table.column>
                        <flux:table.column>Prices</flux:table.column>
                        <flux:table.column>Subscribers</flux:table.column>
                        <flux:table.column>On providers</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->plans as $plan)
                            <flux:table.row wire:key="plan-{{ $plan->id }}">
                                <flux:table.cell>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <p class="font-medium text-slate-950 dark:text-white">{{ $plan->name }}</p>
                                        @if ($plan->is_free->isYes())
                                            <flux:badge size="sm" color="slate" inset="top bottom">Free</flux:badge>
                                        @endif
                                        @if ($plan->is_recommended->isYes())
                                            <flux:badge size="sm" color="lime" inset="top bottom">Recommended</flux:badge>
                                        @endif
                                        @if ($plan->hasTrial())
                                            <flux:badge size="sm" color="sky" inset="top bottom">{{ $plan->trial_days }}-day trial</flux:badge>
                                        @endif
                                    </div>
                                    @if ($plan->includedPlan)
                                        <p class="text-xs text-slate-500 dark:text-slate-400">Everything in {{ $plan->includedPlan->name }}, plus more</p>
                                    @elseif ($plan->description)
                                        <p class="max-w-xs truncate text-xs text-slate-500 dark:text-slate-400">{{ $plan->description }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @forelse ($plan->activePrices() as $price)
                                        <p class="text-sm">{{ $price->priceLabel() }}</p>
                                    @empty
                                        <span class="text-xs text-slate-400">No charge</span>
                                    @endforelse
                                </flux:table.cell>
                                <flux:table.cell>{{ $plan->live_count }}</flux:table.cell>
                                <flux:table.cell>
                                    {{-- Created on a provider the first time somebody subscribes through it. --}}
                                    @forelse ($plan->vendorPlans->where('vendor_plan_id', '!=', '')->unique(fn ($copy) => $copy->vendor->value) as $copy)
                                        <flux:badge size="sm" inset="top bottom">{{ $copy->vendor->label() }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-slate-400">{{ $plan->is_free->isYes() ? '—' : 'On first subscriber' }}</span>
                                    @endforelse
                                </flux:table.cell>
                                <flux:table.cell><x-util.e-badge :enum="$plan->status" /></flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <x-dashboard.gate.menu-item :gate="$pageGate" :level="$gateModify" icon="pencil-square" wire:click="edit({{ $plan->id }})">
                                                Edit
                                            </x-dashboard.gate.menu-item>
                                            <x-dashboard.gate.menu-item :gate="$pageGate" :level="$gateFull" icon="trash" variant="danger" wire:click="confirmDeletePlan({{ $plan->id }})">
                                                Delete
                                            </x-dashboard.gate.menu-item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        @endif

        {{-- ||||||||||||||||||||||||||||||||||||||||||||||||
             SUBSCRIBERS --}}
        @if ($tab === 'subscribers')
            <flux:select wire:model.live="subscriptionStatus" class="sm:max-w-48">
                <flux:select.option value="">All statuses</flux:select.option>
                @foreach (StatusSubscription::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->subscriptions->isEmpty())
                <x-dashboard.workspace-no-record
                    icon="users"
                    label="No subscribers"
                    text="Members who subscribe to a plan show up here."
                />
            @else
                <flux:table :paginate="$this->subscriptions">
                    <flux:table.columns>
                        <flux:table.column>Account</flux:table.column>
                        <flux:table.column>Plan</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Period ends</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->subscriptions as $subscription)
                            <flux:table.row wire:key="subscription-{{ $subscription->id }}">
                                <flux:table.cell>
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $subscription->user?->name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $subscription->user?->email }}</p>
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $subscription->plan?->name }}
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $subscription->priceLabel() }}@if ($subscription->vendor) · {{ $subscription->vendor->label() }}@endif
                                    </p>
                                </flux:table.cell>
                                <flux:table.cell><x-util.e-badge :enum="$subscription->status" /></flux:table.cell>
                                <flux:table.cell>{{ $subscription->current_period_ends_at?->format('M d, Y') ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($subscription->vendor)
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                            <flux:menu>
                                                <x-dashboard.gate.menu-item :gate="$pageGate" :level="$gateModify" icon="arrow-path" wire:click="refreshSubscription({{ $subscription->id }})">
                                                    Check with {{ $subscription->vendor->label() }}
                                                </x-dashboard.gate.menu-item>
                                                @if ($subscription->canBeCancelled())
                                                    <x-dashboard.gate.menu-item :gate="$pageGate" :level="$gateModify" icon="x-circle" variant="danger" wire:click="confirmCancel({{ $subscription->id }})">
                                                        Cancel
                                                    </x-dashboard.gate.menu-item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        @endif
    </flux:card>

    <flux:modal name="planModal" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit plan' : 'New plan' }}</flux:heading>

            <div class="space-y-4">
                <flux:input wire:model="name" label="Name" placeholder="Pro" />
                <flux:textarea wire:model="description" label="Description" rows="2" placeholder="For growing teams that need more room and faster help." />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:switch wire:model.live="is_free" label="Free plan" description="What every account without a subscription has. One plan at a time." />
                    <flux:switch wire:model="is_recommended" label="Recommended" description="Highlighted on the pricing cards. One plan at a time." />
                </div>
            </div>

            @unless ($is_free)
                <flux:separator variant="subtle" />

                <div class="space-y-3">
                    <div>
                        <flux:heading>Prices</flux:heading>
                        <flux:text size="sm">Fill in each interval the plan is offered on; leave the rest empty. Members see what a longer interval saves against the shortest one.</flux:text>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (SubscriptionIntervalEnum::cases() as $interval)
                            <x-form.money-field wire:key="price-{{ $interval->value }}" wire:model="prices.{{ $interval->value }}" :label="$interval->label()" placeholder="Not offered" />
                        @endforeach
                    </div>

                    @if ($editingId)
                        <flux:text size="sm">
                            A new amount applies to new subscribers. Existing subscribers keep the terms
                            they signed up to.
                        </flux:text>
                    @endif

                    <flux:input wire:model="trial_days" type="number" min="0" max="365" label="Free trial" placeholder="14" description="Days an account can try the plan before paying — no card asked for, one trial per account. Leave empty for none." class="sm:max-w-56" />
                </div>
            @endunless

            <flux:separator variant="subtle" />

            <div class="space-y-3">
                <div>
                    <flux:heading>Features</flux:heading>
                    <flux:text size="sm">What the plan gives. The code checks these, and the pricing card lists them.</flux:text>
                </div>

                <flux:select wire:model.live="includes_plan_id" label="Includes everything in" placeholder="No other plan">
                    <flux:select.option value="">No other plan</flux:select.option>
                    @foreach ($this->includableOptions as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
                    @foreach (SubscriptionFeatureEnum::cases() as $feature)
                        <div wire:key="feature-{{ $feature->value }}" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <flux:checkbox wire:model.live="featureOn.{{ $feature->value }}" :label="$feature->label()" />

                            @if ($feature->isLimit() && ($featureOn[$feature->value] ?? false))
                                <flux:input wire:model="featureLimits.{{ $feature->value }}" type="number" min="1" size="sm" placeholder="Unlimited" class="sm:max-w-36" />
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($includes_plan_id !== '')
                    <flux:text size="sm">
                        Tick only what this plan adds or raises — it already has everything in the plan it includes.
                    </flux:text>
                @endif
            </div>

            <flux:switch wire:model="active" label="Offered to members" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save plan</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deletePlanModal"
        title="Delete this plan?"
        icon="trash"
        confirm="Delete plan"
        confirm-icon="trash"
        wire:click="deletePlan"
    >
        A plan nobody has subscribed to, and no other plan includes, can go. Otherwise it stays
        on file — switch it off instead.
    </x-dashboard.confirm-modal>

    <x-dashboard.confirm-modal
        name="cancelSubscriptionModal"
        title="Cancel this subscription?"
        icon="x-circle"
        confirm="Cancel subscription"
        cancel="Keep it"
        wire:click="cancelSubscription"
    >
        The provider stops billing it. The member keeps access until the end of the period they paid for.
    </x-dashboard.confirm-modal>
</div>
