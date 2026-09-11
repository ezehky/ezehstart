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

    Only worth it on a form long enough to scroll. On a short one the dock never
    leaves the viewport and the bar never floats, which is the right outcome but
    also a wrapper doing nothing — pass :float="false" and it stays plain markup.
--}}

@props([
    'position' => 'end',
    'offset' => 0,
    'float' => true,
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

    $alignClasses = match ($position) {
        'end' => 'justify-end',
        'start' => 'justify-start',
        'center' => 'justify-center',
    };

    // The pinned state is a surface of its own: it sits over page content, so it
    // needs a background of its own to stay readable against whatever is under it.
    $floatingClasses = 'fixed bottom-4 z-30 '.$positionClasses.' rounded-xl border border-slate-200 bg-white/90 p-2 shadow-lg backdrop-blur dark:border-white/10 dark:bg-slate-900/90';
@endphp

<div
    x-data="floatingActions({ offset: {{ (int) $offset }}, enabled: {{ $float ? 'true' : 'false' }} })"
    x-ref="dock"
    :style="floating ? `height: ${height}px` : null"
    {{ $attributes->merge() }}
>
    <div
        x-ref="bar"
        class="flex flex-wrap items-center gap-3 {{ $alignClasses }}"
        :class="floating ? '{{ $floatingClasses }} animate-slide-up' : ''"
    >
        {{ $slot }}
    </div>
</div>
