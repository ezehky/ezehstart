{{--
    A form's action bar, pinned to the viewport while its resting place is out of
    sight and dropped back into the page the moment you scroll down to it.

        <x-util.floating-actions>
            <flux:button variant="ghost" :href="route('admin.users')" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">Save changes</flux:button>
        </x-util.floating-actions>

    Put it exactly where the buttons belong — the bottom of a long form. Nothing
    about the markup changes; the bar simply leaves the flow and comes back, and
    the space it leaves is held open so the page never jumps.
--}}

@props([
    'position' => 'end',
])

@php
    // Where the bar sits once it is pinned. It keeps the page's own gutter so a
    // floating button never ends up tight against the edge of a phone screen.
    $positionClasses = match ($position) {
        'end' => 'inset-x-4 justify-end sm:inset-x-6 lg:inset-x-8',
        'start' => 'inset-x-4 justify-start sm:inset-x-6 lg:inset-x-8',
        'center' => 'inset-x-4 justify-center sm:inset-x-6 lg:inset-x-8',
        default => throw new \InvalidArgumentException("Invalid floating actions position: {$position}"),
    };
@endphp

<div class="sticky bottom-20 z-30 lg:bottom-4 backdrop-blur py-2 mx-2 rounded-xl">
    <div class="flex flex-wrap items-center gap-3 {{ $positionClasses }}">
        {{ $slot }}
    </div>
</div>
