@props(['type' => 'line'])

@php
    $toneClasses = match ($type) {
        'area' => 'text-slate-100 dark:text-slate-800',
        default => 'text-slate-300 dark:text-slate-600',
    };
@endphp

<g
    x-html="renderCursor(@js($type))"
    stroke-width="1"
    {{ $attributes->class($toneClasses) }}
></g>
