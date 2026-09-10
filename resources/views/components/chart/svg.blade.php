<svg
    x-init="measure($el)"
    x-on:pointermove="onPointer($event)"
    x-on:pointerleave="clearPointer()"
    {{ $attributes->class('h-full w-full touch-none overflow-visible') }}
>
    {{ $slot }}
</svg>
