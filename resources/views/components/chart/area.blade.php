@props([
    'field',
    'curve' => 'smooth',
    'opacity' => 0.15,
])

<g
    x-init="registerSeries({ field: @js($field), type: 'area' })"
    x-html="renderArea(@js($field), { curve: @js($curve), opacity: @js($opacity) })"
    {{ $attributes->class('text-lime-500 dark:text-lime-400') }}
></g>
