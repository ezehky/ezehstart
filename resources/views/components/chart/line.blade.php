@props([
    'field',
    'curve' => 'smooth',
    'width' => 2,
])

<g
    x-init="registerSeries({ field: @js($field), type: 'line' })"
    x-html="renderLine(@js($field), { curve: @js($curve), width: @js($width) })"
    {{ $attributes->class('text-brand-500 dark:text-brand-400') }}
></g>
