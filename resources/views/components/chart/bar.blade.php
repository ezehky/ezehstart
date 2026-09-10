@props([
    'field',
    'radius' => 4,
    'width' => '65%',
])

<g
    x-init="registerSeries({ field: @js($field), type: 'bar' })"
    x-html="renderBars(@js($field), { radius: @js($radius), width: @js($width) })"
    {{ $attributes->class('text-emerald-500 dark:text-lime-400') }}
></g>
