{{--
    A status shown as a switch rather than a badge, so a row can be retired or
    brought back without opening its form.

        <x-util.status-toggle :status="$item->status" :id="$item->id" gate="content.tags" />
        <x-util.status-toggle :status="$item->status" :id="$item->id" action="togglePublished" />

    The page behind it uses WithStatusToggle, which owns the gate re-check, the
    activity log and the toast. Pass `action` only where a page flips more than one
    column and needs a second method.

    With a `gate`, an account below the level sees the badge instead of the switch —
    the status is still worth reading when it cannot be changed. Hiding the control is
    a courtesy either way; toggleStatus() re-checks before it writes.
--}}

@props([
    'status',
    'id',
    'action' => 'toggleStatus',
    'gate' => null,
    'level' => 'modify',
    'label' => null,
])

@php
    // The switch is a boolean question, so it takes either currency: a column cast to
    // StatusDefault, or a plain bool on a model that never needed the enum.
    $active = $status instanceof \App\Enums\StatusDefault
        ? $status->isActive()
        : (bool) $status;

    // The badge fallback needs an enum, which a bool-backed column does not carry.
    $badgeStatus = $status instanceof \BackedEnum
        ? $status
        : \App\Enums\StatusDefault::tryFrom((int) $active);
@endphp

@if ($gate && ! kGateAction($gate, $level))
    <x-util.status :status="$badgeStatus" />
@else
    <flux:switch
        :checked="$active"
        :label="$label"
        wire:click="{{ $action }}('{{ $id }}')"
        wire:target="{{ $action }}"
        wire:loading.attr="disabled"
        {{ $attributes->merge() }}
    />
@endif
