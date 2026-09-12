@props(['status'])
<flux:badge :color="$status->color()" {{ $attributes->merge(['size' => 'sm', 'inset' => 'top bottom']) }}>
    {{ $status->label() }}
</flux:badge>
