@props([
    'value' => [],
    'gutter' => '8',
])

@php
    $wireModel = $attributes->get('wire:model');

    // Read as CSS shorthand: one value is every side, two are vertical/horizontal.
    $sides = preg_split('/[\s,]+/', trim((string) $gutter)) ?: ['8'];

    $gutters = array_map('floatval', match (count($sides)) {
        1 => [$sides[0], $sides[0], $sides[0], $sides[0]],
        2 => [$sides[0], $sides[1], $sides[0], $sides[1]],
        3 => [$sides[0], $sides[1], $sides[2], $sides[1]],
        default => array_slice($sides, 0, 4),
    });

    // wire:model keeps the series live through a Livewire round trip; :value is
    // rendered once and never talks to the server again.
    $dataExpression = $wireModel
        ? "\$wire.entangle('".e($wireModel)."')"
        : \Illuminate\Support\Js::from($value)->toHtml();
@endphp

<div
    x-data="chart({ data: {!! $dataExpression !!}, gutter: {!! \Illuminate\Support\Js::from($gutters)->toHtml() !!} })"
    {{ $attributes->except('wire:model')->class('relative') }}
>
    {{ $slot }}
</div>
