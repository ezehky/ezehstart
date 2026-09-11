@props(['gate', 'level' => 'modify'])

{{-- The dropdown twin of <x-dashboard.gate.button>: a menu row that is simply not
     there unless the account reaches the level it asks for.

     Same bargain — hiding the row is a courtesy, and the method behind it still does
     its own kGate() check. See gates.md.

     It asks kGateAction(), not kGate(), so a screen shared with the member workspace —
     the image and video libraries — keeps its controls there. That check is on the
     account rather than on the route, because this re-renders on every Livewire
     update and those do not arrive on an admin.* route.

     <x-dashboard.gate.menu-item gate="content.blogs" level="full" icon="trash" variant="danger" wire:click="…">
         Delete
     </x-dashboard.gate.menu-item> --}}

@if (kGateAction($gate, $level))
    <flux:menu.item {{ $attributes }}>{{ $slot }}</flux:menu.item>
@endif
