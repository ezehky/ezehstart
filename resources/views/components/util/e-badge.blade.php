{{--
An enum shown as a badge, or — where the page can flip it — as a switch, so a row
can be retired or brought back without opening its form.

    <x-util.e-badge :enum="$item->status" />
    <x-util.e-badge :enum="$item->status" :id="$item->id" gate="content.tags" />
    <x-util.e-badge :enum="$item->status" :id="$item->id" action="togglePublished" />

Without `id` and `gate` it is a read-only badge, and any enum using
WithEnumHelpers works — colour and label come off the case itself.

The switch is a boolean question, so it only appears for a two-case enum that
answers one: StatusDefault or StatusYes. The page behind it uses
WithStatusToggle, which owns the gate re-check, the activity log and the toast.
Pass `action` only where a page flips more than one column and needs a second
method.

An account below the level sees the badge instead of the switch — the status is
still worth reading when it cannot be changed. Hiding the control is a courtesy
either way; toggleStatus() re-checks before it writes.
--}}

@props([
    'enum',
    'id' => null,
    'action' => 'toggleStatus',
    'gate' => null,
    'level' => 'modify',
    'label' => null,
])

@php
$togglable = $enum instanceof \App\Enums\StatusDefault || $enum instanceof \App\Enums\StatusYes;

$shouldToggle = $id && $gate && $togglable && kGateAction($gate, $level);
@endphp

@if ($shouldToggle)
    <flux:switch
        :checked="$enum->boolValue()"
        :label="$label"
        wire:click="{{ $action }}('{{ $id }}')"
        wire:target="{{ $action }}"
        wire:loading.attr="disabled"
        {{ $attributes->merge() }}
    />
@else
    <flux:badge :color="$enum->color()" {{ $attributes->merge(['size' => 'sm', 'inset' => 'top bottom']) }}>
        {{ $enum->label() }}
    </flux:badge>
@endif
