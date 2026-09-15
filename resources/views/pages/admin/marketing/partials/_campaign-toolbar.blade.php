{{--
    The full-screen builder's own chrome: what is being edited, where in the flow
    it is, and the only way out. Expects $closeRoute from the host.
--}}

@php
    $steps = [
        'details' => 'Details',
        'builder' => 'Builder',
        'recipients' => 'Recipients',
        'review' => 'Review & Send',
    ];
    $stepKeys = array_keys($steps);
    $currentIndex = array_search($step, $stepKeys, true);
@endphp

<div class="flex shrink-0 flex-col gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:flex-row lg:items-center lg:justify-between dark:border-slate-800 dark:bg-slate-950">
    <div class="flex min-w-0 items-center gap-3">
        {{-- A plain button, not a link: leaving with unsaved work has to be a
             question, and a link cannot ask one. --}}
        <flux:button
            variant="ghost"
            size="sm"
            icon="x-mark"
            square
            aria-label="Close builder"
            x-on:click="if (! dirty || confirm('You have changes that have not been saved. Leave anyway?')) { Livewire.navigate(@js($closeRoute)) }"
        />

        <div class="min-w-0">
            <flux:heading level="1" size="lg" class="truncate font-heading">
                {{ $campaign?->name ?: 'New campaign' }}
            </flux:heading>
            <flux:text class="mt-0.5 text-xs">
                @if ($campaign)
                    <x-util.e-badge :enum="$campaign->status" />
                @else
                    Campaign name is internal only and never appears in the email.
                @endif
            </flux:text>
        </div>

        <span x-cloak x-show="dirty" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-300">
            Unsaved
        </span>
    </div>

    <nav aria-label="Campaign steps" class="flex items-center gap-1 self-start rounded-full border border-slate-200 bg-white p-1 lg:self-auto dark:border-slate-800 dark:bg-slate-950">
        @foreach ($steps as $key => $label)
            @php($reachable = $campaign !== null || $key === 'details')
            @php($done = $currentIndex !== false && array_search($key, $stepKeys, true) < $currentIndex)

            <button
                type="button"
                @if ($reachable) wire:click="$set('step', '{{ $key }}')" @else disabled @endif
                @class([
                    'press flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold',
                    'bg-slate-900 text-white dark:bg-lime-400 dark:text-slate-950' => $step === $key,
                    'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-900' => $step !== $key && $reachable,
                    'text-slate-300 dark:text-slate-700' => ! $reachable,
                ])
            >
                @if ($done)
                    <flux:icon name="check-circle" class="size-3.5" />
                @endif
                {{ $label }}
            </button>
        @endforeach
    </nav>
</div>
