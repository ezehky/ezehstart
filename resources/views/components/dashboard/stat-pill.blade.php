@props(['value', 'label', 'tone' => 'slate'])

@php
    $toneClasses = match ($tone) {
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'amber' => 'text-amber-600 dark:text-amber-400',
        'red' => 'text-red-600 dark:text-red-400',
        default => 'text-slate-600 dark:text-slate-300',
    };
@endphp

<div {{ $attributes->class(['rounded-xl border border-slate-100 px-4 py-3 dark:border-slate-800']) }}>
    <span class="block text-lg font-bold {{ $toneClasses }}">{{ $value }}</span>
    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</span>
</div>
