<?php

use App\Enums\GateAccessEnum;
use App\Enums\MailProviderEnum;
use App\Rules\EmailRule;
use App\Services\Mail\MailDeliveryService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Where outgoing email goes: the log (the default — nothing leaves the server),
 * Resend, Brevo, or any SMTP service. Keys are in .env and never shown here; a
 * provider without them cannot be picked. A test email goes through the card
 * that is selected, before or after saving, and shows the provider's answer.
 *
 * The from-addresses are on Email senders; this screen only decides the road.
 */
new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    public string $test_to = '';

    /** The provider's answer to the last test: null before one, '' when it went. */
    public ?string $test_result = null;

    protected function configSubject(): string
    {
        return 'email delivery';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'email-delivery');
        $this->setPageGate('config.email-delivery');
        $this->setConfigInitial();

        data_set($this->config, 'mail-delivery.provider', $this->service->picked()->value);
        $this->currentConfig = $this->config;
        $this->test_to = (string) auth()->user()->email;
    }

    #[Computed]
    public function service(): MailDeliveryService
    {
        return app(MailDeliveryService::class);
    }

    public function selected(): MailProviderEnum
    {
        return MailProviderEnum::tryFrom((string) data_get($this->config, 'mail-delivery.provider')) ?? MailProviderEnum::LOG;
    }

    protected function rules(): array
    {
        return [
            'config.mail-delivery.provider' => ['required', Rule::enum(MailProviderEnum::class)],
        ];
    }

    public function updatedConfig(): void
    {
        $this->test_result = null;
    }

    public function save(): bool
    {
        $this->checkGate(GateAccessEnum::FULL, 'Only somebody with full access can change where email goes.');

        $provider = $this->selected();

        $this->respondError("{$provider->label()} is not set up yet: add ".implode(', ', $provider->missingEnv()).' to .env first.', ! $provider->isReady());

        $this->saveConfig();
        unset($this->service);

        return $this->respondSuccess($provider->delivers()
            ? "Email now goes through {$provider->label()}. Send a test to be sure."
            : 'Email now goes to the log only. Nothing is delivered.');
    }

    public function sendTest(): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->validate(['test_to' => [new EmailRule(required: true, max: 190)]], attributes: ['test_to' => 'test address']);

        $this->test_result = $this->service->sendTest($this->test_to, $this->selected()) ?? '';
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header icon="paper-airplane" subtitle="Which service delivers the site's email. Keys live in .env and are never shown here." />

    @php
        $active = $this->service->active();
        $picked = $this->service->picked();
        $selected = $this->selected();
    @endphp

    {{-- What is happening right now, before anything is changed. --}}
    <flux:card class="flex items-start gap-4">
        <span @class([
            'flex size-11 shrink-0 items-center justify-center rounded-full',
            'bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300' => $active->delivers(),
            'bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-300' => ! $active->delivers(),
        ])><flux:icon :name="$active->icon()" /></span>
        <div>
            <flux:heading>
                @if ($active->delivers())
                    Email is being delivered through {{ $active->label() }}
                @else
                    Email is going to the log — nothing is delivered
                @endif
            </flux:heading>
            <flux:text class="mt-1 text-sm">
                @if ($picked !== $active)
                    {{ $picked->label() }} is picked but missing {{ implode(', ', $picked->missingEnv()) }} in .env, so the log is used meanwhile.
                @elseif (! $active->delivers())
                    Receipts, sign-in codes and reminders are written to storage/logs instead of being sent. Pick a provider below when it is set up.
                @else
                    Receipts, sign-in codes, reminders and campaigns all go this way. The from-addresses are on Email senders.
                @endif
            </flux:text>
        </div>
    </flux:card>

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-4">
            <div>
                <flux:heading size="lg">Provider</flux:heading>
                <flux:text class="mt-1">A provider needs its keys in .env before it can be picked. After changing .env, run <code class="rounded bg-slate-100 px-1 dark:bg-white/10">php artisan config:clear</code> if config is cached.</flux:text>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                @foreach (MailProviderEnum::cases() as $provider)
                    <label wire:key="provider-{{ $provider->value }}" @class([
                        'relative flex cursor-pointer gap-3 rounded-xl border p-4 transition',
                        'border-brand-700 bg-brand-50/50 ring-1 ring-brand-700 dark:border-brand-300 dark:bg-brand-950/40 dark:ring-brand-300' => $selected === $provider,
                        'border-slate-200 hover:border-slate-300 dark:border-white/10' => $selected !== $provider,
                        'opacity-70' => ! $provider->isReady(),
                    ])>
                        <input type="radio" wire:model.live="config.mail-delivery.provider" value="{{ $provider->value }}" class="sr-only" @disabled(! $provider->isReady())>
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200"><flux:icon :name="$provider->icon()" variant="mini" /></span>
                        <span class="min-w-0 grow">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ $provider->label() }}</span>
                                @if ($active === $provider)
                                    <flux:badge size="sm" color="green">In use</flux:badge>
                                @elseif ($provider->isReady())
                                    <flux:badge size="sm" color="sky">Ready</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Not set up</flux:badge>
                                @endif
                            </span>
                            <span class="mt-1 block text-xs leading-relaxed text-slate-500 dark:text-slate-400">{{ $provider->description() }}</span>
                            @if ($provider->requiredEnv() || $provider->optionalEnv())
                                <span class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($provider->requiredEnv() as $env)
                                        <code @class([
                                            'rounded px-1.5 py-0.5 text-[0.68rem]',
                                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => ! in_array($env, $provider->missingEnv(), true),
                                            'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => in_array($env, $provider->missingEnv(), true),
                                        ])>{{ $env }}</code>
                                    @endforeach
                                    @foreach ($provider->optionalEnv() as $env)
                                        <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[0.68rem] text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $env }}</code>
                                    @endforeach
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            <flux:error name="config.mail-delivery.provider" />

            <div class="flex justify-end">
                <x-dashboard.gate.button :gate="$pageGate" :level="$gateFull" type="submit" variant="primary" icon="check">Save</x-dashboard.gate.button>
            </div>
        </flux:card>
    </form>

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="lg">Send a test</flux:heading>
            <flux:text class="mt-1">Goes through <strong>{{ $selected->label() }}</strong> — the selected card, saved or not — and shows the provider's answer.</flux:text>
        </div>
        <form wire:submit="sendTest" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <flux:input wire:model="test_to" type="email" placeholder="you@example.com" class="sm:max-w-80" />
            <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" type="submit" icon="paper-airplane" wire:loading.attr="disabled" wire:target="sendTest">Send test email</x-dashboard.gate.button>
        </form>

        @if ($test_result === '')
            <flux:callout variant="success" icon="check-circle" :heading="$selected->delivers() ? 'Accepted by '.$selected->label().'. Check the inbox (and spam).' : 'Written to the log. Nothing was delivered.'" />
        @elseif ($test_result !== null)
            <flux:callout variant="danger" icon="x-circle" heading="It did not go.">
                <flux:callout.text>{{ str($test_result)->limit(400) }}</flux:callout.text>
            </flux:callout>
        @endif
    </flux:card>
</div>
