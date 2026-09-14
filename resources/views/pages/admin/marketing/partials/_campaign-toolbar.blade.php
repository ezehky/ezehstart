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

<div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
        <flux:heading level="1" size="xl" class="font-heading">
            {{ $campaign?->name ?: 'New campaign' }}
        </flux:heading>
        <flux:text class="mt-1">
            @if ($campaign)
                <x-util.e-badge :enum="$campaign->status" />
            @else
                Campaign name is internal only and never appears in the email.
            @endif
        </flux:text>
    </div>

    <nav aria-label="Campaign steps" class="flex items-center gap-1 rounded-full border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-950">
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
