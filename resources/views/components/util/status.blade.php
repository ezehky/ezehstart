@props(['status'])
<flux:badge :color="$status->color()" {{ $attributes->merge(['size' => 'sm', 'inset' => 'top bottom']) }}>
    {{ kBreakText($status->label()) }}
</flux:badge>
