{{-- The password field.

     The strength note reads as a tooltip rather than as a line of text under the
     input: it only tells somebody anything while they are choosing a password, and
     a rule printed permanently under every field is noise on the screens that are
     not enforcing it.

     Hand-rolled rather than <flux:tooltip>, which is built for a pointer passing
     over something and closes itself again the moment focus moves inside the
     field — including onto the eye toggle, and on the first keystroke. A rule you
     are trying to satisfy has to stay on screen for as long as you are typing, so
     this one is held open by two independent flags and only closes when focus has
     genuinely left the field and the pointer is elsewhere.

     Pass :note="$passwordNote" on the field that has to satisfy the rule and leave
     it off the confirmation — saying it twice helps nobody.

     The opening and closing tags are wrapped separately so the input itself is
     written once; a note-less field renders exactly what it did before. --}}
@props([
    'note' => null,
])

@if (filled($note))
    <div
        class="relative"
        x-data="{
            focused: false,
            hovered: false,
            get open() {
                return this.focused || this.hovered
            },
        }"
        x-on:focusin="focused = true"
        x-on:focusout="focused = $el.contains($event.relatedTarget)"
        x-on:mouseenter="hovered = true"
        x-on:mouseleave="hovered = false"
    >
        <div
            x-cloak
            x-show="open"
            x-transition:enter="transition duration-150 ease-out"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-100 ease-in"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-1"
            role="note"
            class="absolute bottom-full left-0 z-20 mb-2 w-max max-w-xs rounded-lg bg-slate-900 px-3 py-2 text-xs font-medium text-white shadow-lg dark:bg-slate-700 dark:text-slate-100"
        >
            {{ $note }}

            {{-- The pointer. Drawn as a rotated square rather than a border triangle
                 so it inherits the same background in both themes. --}}
            <span class="absolute top-full left-4 -mt-1 size-2 rotate-45 bg-slate-900 dark:bg-slate-700"></span>
        </div>
@endif

<flux:input
    {{ $attributes->merge([
        'label' => 'Password',
    ]) }}
    type="password"
    viewable placeholder="••••••••"
/>

@if (filled($note))
    </div>
@endif
