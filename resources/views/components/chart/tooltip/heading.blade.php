@props([
    'field',
    'format' => null,
    'scale' => null,
])

<p
    x-text="cell(@js($field), @js($format), @js($scale))"
    {{ $attributes->class('font-medium text-slate-900 dark:text-white') }}
></p>
