@props([
    'label',
    'value' => null,
])
<p>
    <strong>{!! $label !!}:</strong>
    {!! $value !!}
</p>
