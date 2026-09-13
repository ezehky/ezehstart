{{-- The password field.

     The strength note reads as a tooltip rather than as a line of text under the
     input: it only tells somebody anything while they are choosing a password, and
     a rule printed permanently under every field is noise on the screens that are
     not enforcing it. Flux opens a tooltip on focus as well as on hover, so the
     note is there for a keyboard as well as for a mouse.

     Pass :note="$passwordNote" on the field that has to satisfy the rule and leave
     it off the confirmation — saying it twice helps nobody.

     The opening and closing tags are wrapped separately so the input itself is
     written once; a note-less field renders exactly what it did before. --}}
@props([
    'note' => null,
])

@if (filled($note))
    <flux:tooltip :content="$note" position="top" class="inline-flex w-full">
@endif

<flux:input
    {{ $attributes->merge([
        'label' => 'Password',
    ]) }}
    type="password"
    viewable placeholder="••••••••"
/>

@if (filled($note))
    </flux:tooltip>
@endif
