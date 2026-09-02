@props([
    'size' => 'md',
    'icon' => 'academic-cap',
    'tone' => 'lime',
])

@php
    switch ($size) {
        case 'xs':
            $iconSize = "size-4.5";
            $containerSize = "size-9";
            break;

        case 'sm':
            $iconSize = "size-6";
            $containerSize = "size-11";
            break;

        default:
            $iconSize = "size-7";
            $containerSize = "size-14";
            break;
    }

    $toneClasses = match ($tone) {
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-300',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        default => 'bg-lime-100 text-lime-700 dark:bg-lime-400/10 dark:text-lime-300',
    };
@endphp

<span
    {{ $attributes->class([
        'grid shrink-0 place-items-center rounded-xl',
        $toneClasses,
        $containerSize,
    ]) }}>
    <flux:icon :name="$icon" class="{{ $iconSize }}" />
</span>
