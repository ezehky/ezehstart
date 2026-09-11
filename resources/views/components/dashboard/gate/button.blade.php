@props(['gate', 'level' => 'modify'])

{{-- An action button that is simply not there unless the account reaches the level it
     asks for.

     Every admin screen has the same three: add at CREATE, edit at MODIFY, delete at
     FULL. Written out longhand that is an @if around every button on fifteen screens,
     and the one somebody forgets is a button that renders for an account that cannot
     use it.

     Hiding is still only a courtesy — the method behind the button does its own
     kGate() check, exactly as gates.md requires. This is what keeps the screen honest
     about what it is offering, not what keeps the data safe.

     It asks kGateAction(), not kGate(), so a screen shared with the member workspace —
     the image and video libraries — keeps its controls there. That check is on the
     account rather than on the route, because this re-renders on every Livewire
     update and those do not arrive on an admin.* route.

     <x-dashboard.gate.button gate="content.blogs" level="create" icon="plus" variant="primary" wire:click="create">
         New post
     </x-dashboard.gate.button> --}}

@if (kGateAction($gate, $level))
    <flux:button {{ $attributes }}>{{ $slot }}</flux:button>
@endif
