@props([
    'percent' => 0,
    'size' => 'size-28',
    'label' => null,
    'color' => 'text-lime-400',
    'trackColor' => 'text-white/10',
    'numberColor' => '',
])

@php
    $radius = 42;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - max(0, min(100, $percent)) / 100);
@endphp

<div {{ $attributes->class(['relative grid shrink-0 place-items-center', $size]) }}>
    <svg class="size-full -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
        <circle cx="50" cy="50" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="8" class="{{ $trackColor }}" />
        <circle
            cx="50" cy="50" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round"
            class="{{ $color }} transition-all duration-700 ease-out"
            stroke-dasharray="{{ $circumference }}"
            stroke-dashoffset="{{ $offset }}"
        />
    </svg>
    <div class="absolute text-center">
        @if ($slot->isEmpty())
            <span class="font-heading text-2xl font-bold {{ $numberColor }}">{{ $percent }}%</span>
            @if ($label)
                <span class="block text-[10px] uppercase tracking-wide text-slate-400">{{ $label }}</span>
            @endif
        @else
            {{ $slot }}
        @endif
    </div>
</div>
