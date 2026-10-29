<?php

use App\Enums\PaymentVendorEnum;
use App\Enums\StatusSubscription;
use App\Enums\SubscriptionIntervalEnum;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\Payment\SubscriptionService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    /** The interval the cards are priced on. */
    public string $interval = '';

    public ?int $priceId = null;

    public string $vendor = '';

    public function mount(): void
    {
        $this->user = auth()->user();

        kSetSiteTitle('subscriptions');

        // Monthly where it is offered, otherwise the shortest interval that is.
        $offered = $this->intervals;
        $this->interval = \in_array(SubscriptionIntervalEnum::MONTHLY, $offered, true)
            ? SubscriptionIntervalEnum::MONTHLY->value
            : ($offered[0]->value ?? '');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return app(SubscriptionService::class)->activePlans();
    }

    /**
     * Every interval some plan is offered on, shortest first — the toggle above
     * the cards.
     *
     * @return array<int, SubscriptionIntervalEnum>
     */
    #[Computed]
    public function intervals(): array
    {
        return $this->plans
            ->flatMap(fn (SubscriptionPlan $plan) => $plan->activePrices()->pluck('billing_interval'))
            ->unique(fn (SubscriptionIntervalEnum $interval) => $interval->value)
            ->sortBy(fn (SubscriptionIntervalEnum $interval) => $interval->averageDays())
            ->values()
            ->all();
    }

    /**
     * One card a plan, priced on the chosen interval — or on the plan's
     * shortest one when it is not offered on that interval, labelled with its
     * own interval so it never reads as the other.
     *
     * @return array<int, array{plan: SubscriptionPlan, price: ?SubscriptionPlanPrice, savings: int, savedAgainst: ?SubscriptionIntervalEnum}>
     */
    #[Computed]
    public function cards(): array
    {
        $interval = SubscriptionIntervalEnum::tryFrom($this->interval) ?? SubscriptionIntervalEnum::MONTHLY;

        return $this->plans->map(function (SubscriptionPlan $plan) use ($interval) {
            $base = $plan->basePrice();
            $price = $plan->is_free->isYes() ? null : ($plan->priceFor($interval) ?? $base);

            return [
                'plan' => $plan,
                'price' => $price,
                'savings' => $price?->savingsAgainst($base) ?? 0,
                'savedAgainst' => $base?->billing_interval,
            ];
        })->all();
    }

    /**
     * What the member has now: the subscription or trial giving access, or
     * failing that the newest one still pending its first payment.
     */
    #[Computed]
    public function current(): ?Subscription
    {
        $service = app(SubscriptionService::class);

        return $service->accessFor($this->user)
            ?? $this->user->subscriptions()->with('plan')->where('status', StatusSubscription::PENDING)->newestFirst()->first();
    }

    /**
     * The plan the member is on — the free plan when nothing else gives access.
     */
    #[Computed]
    public function currentPlanId(): ?int
    {
        $current = $this->current;

        if ($current && ! $current->status->isPending()) {
            return $current->subscription_plan_id;
        }

        return $this->plans->first(fn (SubscriptionPlan $plan) => $plan->is_free->isYes())?->id;
    }

    /**
     * Whether the member can still take a trial: none taken before, and nothing
     * giving access now.
     */
    #[Computed]
    public function canTrial(): bool
    {
        return ! app(SubscriptionService::class)->hasUsedTrial($this->user) && ! $this->current?->grantsAccess();
    }

    /**
     * Whether the member can subscribe now: nothing giving access, or only a
     * trial, which a paid subscription replaces.
     */
    #[Computed]
    public function canSubscribe(): bool
    {
        return ! $this->current?->grantsAccess() || $this->current->isTrial();
    }

    /**
     * @return array<int, PaymentVendorEnum>
     */
    #[Computed]
    public function vendorOptions(): array
    {
        return app(SubscriptionService::class)->vendorOptions();
    }

    /**
     * An impersonating admin sees the page but never starts or stops a
     * subscription — that is a real card, in somebody else's name.
     */
    #[Computed]
    public function canAct(): bool
    {
        return ! app(ImpersonationService::class)->isImpersonating();
    }

    #[Computed]
    public function chosenPrice(): ?SubscriptionPlanPrice
    {
        return $this->priceId ? SubscriptionPlanPrice::query()->with('plan')->find($this->priceId) : null;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // ACTIONS

    protected function rules(): array
    {
        return [
            'priceId' => ['required', Rule::exists('subscription_plan_prices', 'id')],
            'vendor' => ['required', Rule::in(array_map(fn (PaymentVendorEnum $vendor) => $vendor->value, $this->vendorOptions))],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['vendor' => 'payment method'];
    }

    public function choosePrice(int $priceId): void
    {
        abort_if(! $this->canAct, 404);

        $this->resetValidation();
        $this->priceId = $priceId;
        $this->vendor = $this->vendorOptions[0]->value ?? '';
        unset($this->chosenPrice);

        Flux::modal('subscribeModal')->show();
    }

    public function subscribe()
    {
        abort_if(! $this->canAct, 404);

        $this->validate();

        $price = SubscriptionPlanPrice::query()->with('plan')->findOrFail($this->priceId);

        $result = app(SubscriptionService::class)->subscribe($this->user, $price, PaymentVendorEnum::from($this->vendor));

        $this->respondError($result, \is_string($result));

        return $this->redirect($result->gateway->gateway_url);
    }

    public function startTrial(int $planId): bool
    {
        abort_if(! $this->canAct, 404);

        $plan = SubscriptionPlan::query()->findOrFail($planId);

        $result = app(SubscriptionService::class)->startTrial($this->user, $plan);

        $this->respondError($result, \is_string($result));

        unset($this->current, $this->currentPlanId, $this->canTrial, $this->canSubscribe);

        return $this->respondSuccess("Your {$plan->trial_days}-day free trial of {$plan->name} has started.");
    }

    public function cancel(): bool
    {
        abort_if(! $this->canAct, 404);

        $subscription = $this->current;

        abort_unless($subscription && $subscription->user_id === $this->user->id, 404);

        $error = app(SubscriptionService::class)->cancel($subscription);

        unset($this->current);

        $this->respondError($error, $error !== null);

        return $this->respondSuccess('Your subscription will not renew. You keep access until the end of the period you paid for.');
    }
};
?>

<div class="space-y-6">
    @if ($this->current)
        <flux:card class="space-y-4">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading level="2" size="lg">Your subscription</flux:heading>
                    <flux:text class="mt-1">
                        {{ $this->current->plan?->name }} · {{ $this->current->priceLabel() }}@if ($this->current->vendor) · via {{ $this->current->vendor->label() }}@endif
                    </flux:text>
                </div>
                <x-util.e-badge :enum="$this->current->status" />
            </div>

            <flux:text>
                @if ($this->current->isTrial())
                    Your free trial ends on {{ $this->current->current_period_ends_at?->format('M d, Y') }}. Subscribe before then to keep it —
                    paying starts your first period straight away.
                @elseif ($this->current->status->isPending())
                    Waiting for {{ $this->current->vendor?->label() }} to confirm your first payment.
                @elseif ($this->current->status->isCancelled())
                    It will not renew. You have access until {{ $this->current->current_period_ends_at?->format('M d, Y') }}.
                @elseif ($this->current->status->isPastDue())
                    Your last payment did not go through. {{ $this->current->vendor?->label() }} will try again.
                @else
                    Renews on {{ $this->current->current_period_ends_at?->format('M d, Y') }}.
                @endif
            </flux:text>

            @if ($this->canAct && $this->current->canBeCancelled())
                <div>
                    <flux:button variant="ghost" icon="x-circle" x-on:click="$flux.modal('cancelModal').show()">
                        Cancel subscription
                    </flux:button>
                </div>
            @endif
        </flux:card>
    @endif

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Plans</flux:heading>
                <flux:text class="mt-1">Billed automatically by the payment provider you choose. Cancel any time.</flux:text>
            </div>

            @if (\count($this->intervals) > 1)
                <flux:radio.group wire:model.live="interval" variant="segmented" size="sm">
                    @foreach ($this->intervals as $option)
                        <flux:radio value="{{ $option->value }}" label="{{ $option->label() }}" />
                    @endforeach
                </flux:radio.group>
            @endif
        </div>

        @if ($this->plans->isEmpty())
            <x-dashboard.workspace-no-record
                icon="sparkles"
                label="No plans yet"
                text="Subscription plans show up here once they are available."
            />
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->cards as ['plan' => $plan, 'price' => $price, 'savings' => $savings, 'savedAgainst' => $savedAgainst])
                    @php($isCurrent = $this->currentPlanId === $plan->id)
                    @php($recommended = $plan->is_recommended->isYes())

                    <div
                        wire:key="plan-{{ $plan->id }}"
                        @class([
                            'relative flex flex-col gap-4 rounded-xl border p-5',
                            'border-brand-500 ring-1 ring-brand-500 dark:border-brand-400 dark:ring-brand-400' => $recommended,
                            'border-slate-200 dark:border-slate-700' => ! $recommended,
                        ])
                    >
                        <div class="flex items-start justify-between gap-2">
                            <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                            @if ($recommended)
                                <flux:badge size="sm" color="lime">Recommended</flux:badge>
                            @endif
                        </div>

                        <div>
                            @if (! $price)
                                <p class="text-2xl font-semibold text-slate-950 dark:text-white">Free</p>
                            @else
                                <p class="text-2xl font-semibold text-slate-950 dark:text-white">{{ $price->priceLabel() }}</p>
                                @if ($savings > 0)
                                    <flux:badge size="sm" color="emerald" class="mt-2">Save {{ $savings }}% against {{ $savedAgainst?->label(true) }}</flux:badge>
                                @endif
                            @endif
                        </div>

                        @if ($plan->description)
                            <flux:text>{{ $plan->description }}</flux:text>
                        @endif

                        @if ($plan->includedPlan || $plan->featureLines() !== [])
                            <ul class="space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                                @if ($plan->includedPlan)
                                    <li class="font-medium text-slate-950 dark:text-white">Everything in {{ $plan->includedPlan->name }}, plus:</li>
                                @endif
                                @foreach ($plan->featureLines() as $line)
                                    <li class="flex gap-2">
                                        <flux:icon.check class="size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                                        {{ $line }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-auto space-y-2">
                            @if ($isCurrent && ! $this->current?->isTrial())
                                <flux:button class="w-full" disabled>Current plan</flux:button>
                            @elseif ($this->canAct && $price)
                                @if ($this->canSubscribe && $this->vendorOptions !== [])
                                    <flux:button class="w-full" :variant="$recommended || $isCurrent ? 'primary' : 'filled'" wire:click="choosePrice({{ $price->id }})">
                                        {{ $isCurrent ? 'Subscribe to keep it' : 'Subscribe' }}
                                    </flux:button>
                                @endif
                                @if ($plan->hasTrial() && $this->canTrial)
                                    <flux:button class="w-full" variant="ghost" wire:click="startTrial({{ $plan->id }})">
                                        Try it free for {{ $plan->trial_days }} days
                                    </flux:button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    @if ($this->canAct && $this->vendorOptions !== [])
        <flux:modal name="subscribeModal" class="max-w-md">
            <form wire:submit="subscribe" class="space-y-4">
                <flux:heading size="lg">Choose how to pay</flux:heading>

                <flux:text>
                    @if ($this->chosenPrice)
                        {{ $this->chosenPrice->plan?->name }} at {{ $this->chosenPrice->priceLabel() }}.
                    @endif
                    You will be taken to the provider to pay the first period. After that it
                    renews automatically until you cancel.
                </flux:text>

                <flux:radio.group wire:model="vendor" label="Pay with" variant="cards" class="flex-col">
                    @foreach ($this->vendorOptions as $option)
                        <flux:radio value="{{ $option->value }}" label="{{ $option->label() }}" />
                    @endforeach
                </flux:radio.group>

                <div class="flex justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Continue to payment</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->current?->canBeCancelled())
        <x-dashboard.confirm-modal
            name="cancelModal"
            title="Cancel your subscription?"
            icon="x-circle"
            confirm="Cancel subscription"
            cancel="Keep it"
            wire:click="cancel"
        >
            It will not renew. You keep access until
            {{ $this->current->current_period_ends_at?->format('M d, Y') ?? 'the end of the current period' }}.
        </x-dashboard.confirm-modal>
    @endif
</div>
