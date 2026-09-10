@props([
    'field',
    'radius' => 3,
    'strokeWidth' => 2,
])

<g
    x-init="registerSeries({ field: @js($field), type: 'point' })"
    x-html="renderPoints(@js($field), { radius: @js($radius), strokeWidth: @js($strokeWidth) })"
    {{ $attributes->class('text-lime-500 dark:text-lime-400') }}
></g>
