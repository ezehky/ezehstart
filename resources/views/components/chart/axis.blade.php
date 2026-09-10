@props([
    'axis',
    'field' => null,
    'scale' => 'categorical',
    'format' => null,
    'tickCount' => null,
    'tickPrefix' => null,
    'tickSuffix' => null,
    'min' => null,
    'max' => null,
])

@php
    $config = [
        'field' => $field,
        'scale' => $scale,
        'format' => $format,
        'tickCount' => $tickCount,
        'tickPrefix' => $tickPrefix,
        'tickSuffix' => $tickSuffix,
        'min' => $min,
        'max' => $max,
    ];
@endphp

{{-- Holds the configuration; the grid, line and tick children below read it back by name. --}}
<g x-init="registerAxis(@js($axis), @js($config))" {{ $attributes }}>
    {{ $slot }}
</g>
