<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusSubscription;
use App\Enums\SubscriptionIntervalEnum;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\ActivityLogService;
use App\Services\Payment\SubscriptionService;
use App\Traits\WithGateProps;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
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

    public string $features = '';

    public string $amount = '';

    public string $billing_interval = 'monthly';

    public bool $active = true;

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
            ->withCount(['subscriptions as live_count' => fn (Builder $query) => $query->live()])
            ->with('vendorPlans')
            ->cheapestFirst()
            ->get();
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
            'features' => ['nullable', 'string', 'max:3000'],
            'amount' => ['required', 'numeric', 'min:1'],
            'billing_interval' => ['required', Rule::enum(SubscriptionIntervalEnum::class)],
            'active' => ['boolean'],
        ];
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetValidation();
        $this->reset('editingId', 'name', 'description', 'features', 'amount');
        $this->billing_interval = SubscriptionIntervalEnum::MONTHLY->value;
        $this->active = true;

        Flux::modal('planModal')->show();
    }

    public function edit(int $planId): void
    {
        $this->checkGate();

        $plan = SubscriptionPlan::query()->findOrFail($planId);

        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->features = implode("\n", (array) $plan->features);
        $this->amount = (string) $plan->amount;
        $this->billing_interval = $plan->billing_interval->value;
        $this->active = $plan->status->isActive();

        Flux::modal('planModal')->show();
    }

    /**
     * A change to the price, currency or interval forgets the plan's vendor
     * copies, so the next subscriber is billed the new terms. Subscribers
     * already on the plan keep what they signed up to.
     */
    public function save(): bool
    {
        $this->checkGate($this->editingId === null ? GateAccessEnum::CREATE : GateAccessEnum::MODIFY);

        $this->validate();

        $plan = $this->editingId ? SubscriptionPlan::query()->findOrFail($this->editingId) : new SubscriptionPlan;
        $isNew = ! $plan->exists;

        $plan->name = $this->name;
        $plan->slug = $isNew ? $this->uniqueSlug($this->name) : $plan->slug;
        $plan->description = $this->description ?: null;
        $plan->features = array_values(array_filter(array_map('trim', explode("\n", $this->features)))) ?: null;
        $plan->amount = (float) $this->amount;
        $plan->currency = $plan->currency ?: strtoupper(kDefaultCurrency()['code']);
        $plan->billing_interval = SubscriptionIntervalEnum::from($this->billing_interval);
        $plan->status = $this->active ? StatusDefault::ACTIVE : StatusDefault::INACTIVE;

        $termsChanged = ! $isNew && $plan->isDirty(['amount', 'currency', 'billing_interval']);

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($plan);

        $plan->save();

        if ($termsChanged) {
            app(SubscriptionService::class)->planChanged($plan);
        }

        $serviceInstance->logActivity(
            $isNew ? ActivityActionEnum::CREATE : ActivityActionEnum::UPDATE,
            " subscription plan: {$plan->name}",
            $affectedColumns,
            model: $plan,
        );

        Flux::modal('planModal')->close();
        unset($this->plans);

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
                        <flux:table.column>Price</flux:table.column>
                        <flux:table.column>Subscribers</flux:table.column>
                        <flux:table.column>On providers</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->plans as $plan)
                            <flux:table.row wire:key="plan-{{ $plan->id }}">
                                <flux:table.cell>
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $plan->name }}</p>
                                    @if ($plan->description)
                                        <p class="max-w-xs truncate text-xs text-slate-500 dark:text-slate-400">{{ $plan->description }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $plan->priceLabel() }}</flux:table.cell>
                                <flux:table.cell>{{ $plan->live_count }}</flux:table.cell>
                                <flux:table.cell>
                                    {{-- Created on a provider the first time somebody subscribes through it. --}}
                                    @forelse ($plan->vendorPlans->where('vendor_plan_id', '!=', '') as $copy)
                                        <flux:badge size="sm" inset="top bottom">{{ $copy->vendor->label() }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-slate-400">On first subscriber</span>
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
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $subscription->priceLabel() }} · {{ $subscription->vendor->label() }}</p>
                                </flux:table.cell>
                                <flux:table.cell><x-util.e-badge :enum="$subscription->status" /></flux:table.cell>
                                <flux:table.cell>{{ $subscription->current_period_ends_at?->format('M d, Y') ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end">
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
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        @endif
    </flux:card>

    <flux:modal name="planModal" class="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit plan' : 'New plan' }}</flux:heading>

            <flux:input wire:model="name" label="Name" placeholder="Pro" />
            <flux:textarea wire:model="description" label="Description" rows="2" />
            <flux:textarea wire:model="features" label="Features" rows="4" description="One per line. Shown as a checklist on the plan." />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.money-field wire:model="amount" label="Price" />
                <flux:select wire:model="billing_interval" label="Billed">
                    @foreach (SubscriptionIntervalEnum::cases() as $interval)
                        <flux:select.option value="{{ $interval->value }}">{{ $interval->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($editingId)
                <flux:text size="sm">
                    Changing the price or how often it bills applies to new subscribers. Existing
                    subscribers keep the terms they signed up to.
                </flux:text>
            @endif

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
        A plan nobody has subscribed to can go. One with subscribers stays on file — switch it off instead.
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
