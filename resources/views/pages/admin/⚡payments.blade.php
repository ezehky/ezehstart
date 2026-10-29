<?php

use App\Enums\PaymentVendorEnum;
use App\Enums\StatusPaymentWebhook;
use App\Models\PaymentTrace;
use App\Models\PaymentWebhook;
use App\Services\Payment\PaymentFeeService;
use App\Services\Payment\PaymentService;
use App\Traits\WithGateProps;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithGateProps, WithPagination;

    #[Url]
    public string $tab = 'webhooks';

    #[Url]
    public string $webhookStatus = '';

    /** What the inspect dialog is showing, already pretty-printed. */
    public string $inspectTitle = '';

    public string $inspectBody = '';

    /**
     * The last health check, by vendor value. Empty until somebody runs one —
     * it calls every vendor, so it is never run just because the page opened.
     *
     * @var array<string, ?string>
     */
    public array $health = [];

    public bool $healthChecked = false;

    /**
     * The fee dialog. Its own three fields rather than WithSiteConfigProcessor:
     * that trait carries the whole site configuration through the browser and
     * saves it back whole, and an account here for payments has no business
     * reading or writing the security switches.
     */
    public string $feeVendor = '';

    public $feePercent = 0;

    public $feeFlat = 0;

    public $feeCap = 0;

    public function mount(): void
    {
        kSetSiteTitle('payments');
        $this->setPageGate('payments');

        if (! \in_array($this->tab, ['webhooks', 'traces', 'gateways'], true)) {
            $this->tab = 'webhooks';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedWebhookStatus(): void
    {
        $this->resetPage();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    #[Computed]
    public function webhooks()
    {
        return PaymentWebhook::query()
            ->when($this->webhookStatus !== '', fn (Builder $query) => $query->where('status', (int) $this->webhookStatus))
            ->newestFirst()
            ->paginate(20);
    }

    #[Computed]
    public function traces()
    {
        return PaymentTrace::query()
            ->with('transaction:id,reference')
            ->newestFirst()
            ->paginate(20);
    }

    #[Computed]
    public function failedWebhooks(): int
    {
        return PaymentWebhook::query()->failed()->count();
    }

    #[Computed]
    public function tracingEnabled(): bool
    {
        return (bool) config('payments.tracing.enabled');
    }

    /**
     * Every vendor the kit can talk to and whether this install can use it. Keys
     * are never shown — only whether they are there.
     *
     * @return array<int, array{vendor: PaymentVendorEnum, configured: bool, available: bool, default: bool, fee: ?string}>
     */
    #[Computed]
    public function gateways(): array
    {
        return collect(PaymentVendorEnum::cases())
            ->reject(fn (PaymentVendorEnum $vendor) => $vendor->isManual())
            ->map(fn (PaymentVendorEnum $vendor) => [
                'vendor' => $vendor,
                'configured' => $vendor->isConfigured(),
                'available' => $vendor->isAvailable(),
                'default' => config('payments.default') === $vendor->value,
                'fee' => app(PaymentFeeService::class)->describe($vendor),
            ])
            ->values()
            ->all();
    }

    /**
     * The provider the fee dialog is open for, for its heading.
     */
    #[Computed]
    public function feeVendorLabel(): string
    {
        return PaymentVendorEnum::tryFrom($this->feeVendor)?->label() ?? '';
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // ACTIONS

    public function retryWebhook(int $webhookId): bool
    {
        // The menu item is hidden without MODIFY, which stops nobody with a console.
        $this->checkGate();

        $webhook = PaymentWebhook::query()->whereKey($webhookId)->first();

        abort_unless((bool) $webhook, 404);

        $error = app(PaymentService::class)->retryWebhook($webhook);

        unset($this->webhooks, $this->failedWebhooks);

        $this->respondError($error, $error !== null);

        return $this->respondSuccess('The webhook has been put back on the queue.');
    }

    public function inspectWebhook(int $webhookId): void
    {
        $webhook = PaymentWebhook::query()->whereKey($webhookId)->firstOrFail();

        $this->inspect($webhook->label(), $webhook->payload);
    }

    public function inspectTrace(int $traceId): void
    {
        $trace = PaymentTrace::query()->whereKey($traceId)->firstOrFail();

        $this->inspect("{$trace->vendor->label()} · {$trace->trace_step->label()}", (array) $trace->context);
    }

    /**
     * One authenticated call to every configured vendor. Asks for MODIFY: it is
     * read-only, but it is traffic to every vendor and should not be a thing a
     * view-only account can set off on a loop.
     */
    public function checkHealth(): void
    {
        $this->checkGate();

        $this->health = app(PaymentService::class)->health();
        $this->healthChecked = true;
    }

    public function editFee(string $vendor): void
    {
        $this->checkGate();

        $case = PaymentVendorEnum::tryFrom($vendor);

        abort_unless($case && ! $case->isManual(), 404);

        $terms = app(PaymentFeeService::class)->termsFor($case);

        $this->resetValidation();
        $this->feeVendor = $case->value;
        $this->feePercent = $terms['percent'];
        $this->feeFlat = $terms['flat'];
        $this->feeCap = $terms['cap'];

        unset($this->feeVendorLabel);

        Flux::modal('feeModal')->show();
    }

    protected function rules(): array
    {
        return [
            'feePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'feeFlat' => ['required', 'numeric', 'min:0'],
            'feeCap' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'feePercent' => 'percentage',
            'feeFlat' => 'flat amount',
            'feeCap' => 'cap',
        ];
    }

    /**
     * What a customer pays on top of a purchase through this vendor. Takes
     * effect on the next checkout; a payment already opened keeps its fee.
     */
    public function saveFee(): bool
    {
        $this->checkGate();

        $vendor = PaymentVendorEnum::tryFrom($this->feeVendor);

        abort_unless($vendor && ! $vendor->isManual(), 404);

        $this->validate();

        $error = app(PaymentFeeService::class)->saveTerms($vendor, (float) $this->feePercent, (float) $this->feeFlat, (float) $this->feeCap);

        $this->respondError($error, $error !== null);

        unset($this->gateways);

        Flux::modal('feeModal')->close();

        return $this->respondSuccess($vendor->label().' processing fee saved.');
    }

    // Tools

    /**
     * @param  array<mixed>  $data
     */
    protected function inspect(string $title, array $data): void
    {
        $this->inspectTitle = $title;
        $this->inspectBody = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        Flux::modal('inspectModal')->show();
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Payments</flux:heading>
                <flux:text class="mt-1">What the payment providers sent, what was asked of them, and which of them this site can use.</flux:text>
            </div>

            <flux:radio.group wire:model.live="tab" variant="segmented">
                <flux:radio value="webhooks" label="Webhooks" />
                <flux:radio value="traces" label="Traces" />
                <flux:radio value="gateways" label="Gateways" />
            </flux:radio.group>
        </div>

        {{-- ||||||||||||||||||||||||||||||||||||||||||||||||
             WEBHOOKS --}}
        @if ($tab === 'webhooks')
            @if ($this->failedWebhooks > 0)
                <flux:callout color="red" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ kPluralize('webhook', $this->failedWebhooks) }} failed every attempt. Retry them
                        once the cause is fixed — the provider will not send them again.
                    </flux:callout.text>
                </flux:callout>
            @endif

            <flux:select wire:model.live="webhookStatus" class="sm:max-w-48">
                <flux:select.option value="">All statuses</flux:select.option>
                @foreach (StatusPaymentWebhook::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->webhooks->isEmpty())
                <x-dashboard.workspace-no-record
                    icon="signal"
                    label="No webhooks yet"
                    text="Signed calls from payment providers show up here as they arrive."
                />
            @else
                <flux:table :paginate="$this->webhooks">
                    <flux:table.columns>
                        <flux:table.column>Event</flux:table.column>
                        <flux:table.column>Reference</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Received</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->webhooks as $webhook)
                            <flux:table.row wire:key="webhook-{{ $webhook->id }}">
                                <flux:table.cell>
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $webhook->vendor->label() }}</p>
                                    <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $webhook->event_name ?? '—' }}</p>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <p class="font-mono text-xs">{{ $webhook->reference ?? '—' }}</p>
                                    <x-util.e-badge :enum="$webhook->topic" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-util.e-badge :enum="$webhook->status" />
                                    @if ($webhook->last_error)
                                        <p class="mt-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">{{ str()->limit($webhook->last_error, 120) }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $webhook->createdDatetimeHuman() }}
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ kPluralize('attempt', $webhook->attempts) }}</p>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="code-bracket" wire:click="inspectWebhook({{ $webhook->id }})">Payload</flux:menu.item>
                                            @if ($webhook->isRetryable())
                                                <x-dashboard.gate.menu-item
                                                    :gate="$pageGate"
                                                    :level="$gateModify"
                                                    icon="arrow-path"
                                                    wire:click="retryWebhook({{ $webhook->id }})"
                                                >
                                                    Retry
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

        {{-- ||||||||||||||||||||||||||||||||||||||||||||||||
             TRACES --}}
        @if ($tab === 'traces')
            @unless ($this->tracingEnabled)
                <flux:callout color="sky" icon="information-circle">
                    <flux:callout.text>
                        Tracing is off. Set PAYMENTS_TRACING=true while diagnosing an integration;
                        every call to a provider is then recorded here, with keys and card details removed.
                    </flux:callout.text>
                </flux:callout>
            @endunless

            @if ($this->traces->isEmpty())
                <x-dashboard.workspace-no-record
                    icon="magnifying-glass-circle"
                    label="No traces"
                    text="Calls to payment providers are recorded here while tracing is on."
                />
            @else
                <flux:table :paginate="$this->traces">
                    <flux:table.columns>
                        <flux:table.column>Call</flux:table.column>
                        <flux:table.column>Transaction</flux:table.column>
                        <flux:table.column>Result</flux:table.column>
                        <flux:table.column>When</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->traces as $trace)
                            <flux:table.row wire:key="trace-{{ $trace->id }}">
                                <flux:table.cell>
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $trace->vendor->label() }} · {{ $trace->trace_step->label() }}</p>
                                    <p class="max-w-xs truncate font-mono text-xs text-slate-500 dark:text-slate-400">{{ $trace->method }} {{ $trace->endpoint }}</p>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $trace->transaction?->reference ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$trace->succeeded ? 'green' : 'red'" inset="top bottom">
                                        {{ $trace->http_status ?? 'No answer' }}
                                    </flux:badge>
                                    @if ($trace->message)
                                        <p class="mt-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">{{ str()->limit($trace->message, 120) }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $trace->createdDatetimeHuman() }}
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $trace->duration_ms }} ms</p>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" variant="ghost" icon="code-bracket" wire:click="inspectTrace({{ $trace->id }})" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        @endif

        {{-- ||||||||||||||||||||||||||||||||||||||||||||||||
             GATEWAYS --}}
        @if ($tab === 'gateways')
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:text>
                    Keys live in the environment, never on this screen. A provider is offered at
                    checkout once every key it needs is set and it is not switched off.
                </flux:text>

                <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" icon="signal" wire:click="checkHealth">
                    Check connections
                </x-dashboard.gate.button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Provider</flux:table.column>
                    <flux:table.column>Keys</flux:table.column>
                    <flux:table.column>Checkout</flux:table.column>
                    <flux:table.column>Connection</flux:table.column>
                    <flux:table.column>Processing fee</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->gateways as $row)
                        <flux:table.row wire:key="gateway-{{ $row['vendor']->value }}">
                            <flux:table.cell>
                                <span class="font-medium text-slate-950 dark:text-white">{{ $row['vendor']->label() }}</span>
                                @if ($row['default'])
                                    <flux:badge size="sm" color="lime" inset="top bottom">Default</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$row['configured'] ? 'green' : 'zinc'" inset="top bottom">
                                    {{ $row['configured'] ? 'Set' : 'Missing' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$row['available'] ? 'green' : 'zinc'" inset="top bottom">
                                    {{ $row['available'] ? 'Offered' : 'Off' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (! $healthChecked || ! array_key_exists($row['vendor']->value, $health))
                                    <span class="text-slate-400">—</span>
                                @elseif ($health[$row['vendor']->value] === null)
                                    <flux:badge size="sm" color="green" inset="top bottom">Connected</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red" inset="top bottom">Failed</flux:badge>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $health[$row['vendor']->value] }}</p>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <span @class(['text-slate-900 dark:text-white' => $row['fee'], 'text-slate-400' => ! $row['fee']])>{{ $row['fee'] ?? 'None' }}</span>
                                    <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" type="button" size="xs" variant="ghost" icon="pencil-square" tooltip="Edit fee" wire:click="editFee('{{ $row['vendor']->value }}')" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal name="feeModal" class="w-full max-w-md">
        <form wire:submit="saveFee" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $this->feeVendorLabel }} processing fee</flux:heading>
                <flux:text class="mt-1">
                    Added on top of a purchase paid through this provider, and shown to the
                    customer before they pay. Wallet deposits are never charged it.
                </flux:text>
            </div>

            <flux:input wire:model="feePercent" type="number" step="0.01" min="0" max="100" label="Percentage of the price" placeholder="e.g. 1.5" suffix="%" />

            <div wire:key="fee-money-{{ $feeVendor }}" class="grid gap-4 sm:grid-cols-2">
                <x-form.money-field wire:model="feeFlat" label="Plus a flat amount" placeholder="e.g. 100" />
                <x-form.money-field wire:model="feeCap" label="Capped at" placeholder="0 for no cap" description="0 for no cap." />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save fee</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="inspectModal" class="max-w-2xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $inspectTitle }}</flux:heading>
            <flux:text size="sm">Credentials and card fields were replaced before this was stored.</flux:text>
            <pre class="max-h-[60vh] overflow-auto rounded-lg bg-slate-950 p-4 font-mono text-xs text-slate-100">{{ $inspectBody }}</pre>
        </div>
    </flux:modal>
</div>
