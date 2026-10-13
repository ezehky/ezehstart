<?php

use App\Enums\PaymentVendorEnum;
use App\Enums\StatusSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
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

    public ?int $planId = null;

    public string $vendor = '';

    public function mount(): void
    {
        $this->user = auth()->user();

        kSetSiteTitle('subscriptions');
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
     * What the member has now: the subscription giving access, or failing that
     * the newest one still pending its first payment.
     */
    #[Computed]
    public function current(): ?Subscription
    {
        $service = app(SubscriptionService::class);

        return $service->accessFor($this->user)
            ?? $this->user->subscriptions()->with('plan')->where('status', StatusSubscription::PENDING)->newestFirst()->first();
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

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // ACTIONS

    protected function rules(): array
    {
        return [
            'planId' => ['required', Rule::exists('subscription_plans', 'id')],
            'vendor' => ['required', Rule::in(array_map(fn (PaymentVendorEnum $vendor) => $vendor->value, $this->vendorOptions))],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['vendor' => 'payment method'];
    }

    public function choosePlan(int $planId): void
    {
        abort_if(! $this->canAct, 404);

        $this->resetValidation();
        $this->planId = $planId;
        $this->vendor = $this->vendorOptions[0]->value ?? '';

        Flux::modal('subscribeModal')->show();
    }

    public function subscribe()
    {
        abort_if(! $this->canAct, 404);

        $this->validate();

        $plan = SubscriptionPlan::query()->findOrFail($this->planId);

        $result = app(SubscriptionService::class)->subscribe($this->user, $plan, PaymentVendorEnum::from($this->vendor));

        $this->respondError($result, \is_string($result));

        return $this->redirect($result->gateway->gateway_url);
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
                    <flux:text class="mt-1">{{ $this->current->plan?->name }} · {{ $this->current->priceLabel() }} · via {{ $this->current->vendor->label() }}</flux:text>
                </div>
                <x-util.e-badge :enum="$this->current->status" />
            </div>

            <flux:text>
                @if ($this->current->status->isPending())
                    Waiting for {{ $this->current->vendor->label() }} to confirm your first payment.
                @elseif ($this->current->status->isCancelled())
                    It will not renew. You have access until {{ $this->current->current_period_ends_at?->format('M d, Y') }}.
                @elseif ($this->current->status->isPastDue())
                    Your last payment did not go through. {{ $this->current->vendor->label() }} will try again.
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
        <div>
            <flux:heading level="2" size="lg">Plans</flux:heading>
            <flux:text class="mt-1">Billed automatically by the payment provider you choose. Cancel any time.</flux:text>
        </div>

        @if ($this->plans->isEmpty() || $this->vendorOptions === [])
            <x-dashboard.workspace-no-record
                icon="sparkles"
                label="No plans yet"
                text="Subscription plans show up here once they are available."
            />
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->plans as $plan)
                    <div wire:key="plan-{{ $plan->id }}" class="flex flex-col gap-4 rounded-xl border border-slate-200 p-5 dark:border-slate-700">
                        <div>
                            <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                            <p class="mt-1 text-2xl font-semibold text-slate-950 dark:text-white">{{ $plan->priceLabel() }}</p>
                        </div>

                        @if ($plan->description)
                            <flux:text>{{ $plan->description }}</flux:text>
                        @endif

                        @if ($plan->features)
                            <ul class="space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                                @foreach ($plan->features as $feature)
                                    <li class="flex gap-2">
                                        <flux:icon.check class="size-4 shrink-0 text-lime-600" />
                                        {{ $feature }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-auto">
                            @if ($this->current?->subscription_plan_id === $plan->id && ! $this->current->status->isPending())
                                <flux:button class="w-full" disabled>Current plan</flux:button>
                            @elseif ($this->canAct && ! $this->current?->grantsAccess())
                                <flux:button class="w-full" variant="primary" wire:click="choosePlan({{ $plan->id }})">Subscribe</flux:button>
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
