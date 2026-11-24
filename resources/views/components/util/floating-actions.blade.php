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

    Every long form gets one, so the save button is never a scroll away. Inside
    a <flux:modal>, pass `in-modal`: the dialog is its own scroll container, so
    the bar pins to the dialog's bottom edge rather than above the page's
    floating menu.

        <x-util.floating-actions in-modal>
            …
        </x-util.floating-actions>
--}}

@props([
    'position' => 'end',
    'inModal' => false,
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

    // On a page the bar clears the member workspace's bottom menu on phones. A
    // dialog has no such menu, and its own padding is the only gutter it needs.
    $stickClasses = $inModal
        ? 'sticky -bottom-6 z-30 -mx-6 bg-white/85 px-6 py-3 backdrop-blur dark:bg-slate-800/85'
        : 'sticky bottom-20 z-30 lg:bottom-4 backdrop-blur py-2 mx-2 rounded-xl';
@endphp

{{-- Extra classes land on the sticky element itself, which is what lets a form
     laid out as a grid span it across every column with class="lg:col-span-2".
     A wrapper div would be only as tall as the bar and never stick. --}}
<div {{ $attributes->class($stickClasses) }}>
    <div class="flex flex-wrap items-center gap-3 {{ $positionClasses }}">
        {{ $slot }}
    </div>
</div>
