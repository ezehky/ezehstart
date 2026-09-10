@props([
    'field',
    'label' => null,
    'format' => null,
    'scale' => 'linear',
    'prefix' => null,
    'suffix' => null,
])

<div {{ $attributes->class('mt-1 flex items-center justify-between gap-6') }}>
    @if ($label)
        <span class="text-slate-500 dark:text-slate-400">{{ $label }}</span>
    @endif
    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
        {!! $prefix !!}<span x-text="cell(@js($field), @js($format), @js($scale))"></span>{!! $suffix !!}
    </span>
</div>
