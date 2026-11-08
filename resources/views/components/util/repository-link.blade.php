{{--
    A link to the source code on GitHub — the public header, the public footer and
    the dashboard sidebar.

        <x-util.repository-link class="…" />                       icon only
        <x-util.repository-link class="…">GitHub</x-util.repository-link>

    The address is `app.repository` (APP_REPOSITORY_URL). It points at the kit's own
    repository; a project made from the kit sets its own, or sets it empty, and
    every link goes away together. Nothing renders when it is blank.

    Opens in a new tab: it leaves the site, and a visitor halfway through a form
    should not lose it.

    label    the accessible name when there is no slot text to give one.
--}}
@props([
    'href' => config('app.repository'),
    'label' => __('View the source on GitHub'),
])

@if (filled($href))
    <a
        href="{{ $href }}"
        target="_blank"
        rel="noopener noreferrer"
        @if ($slot->isEmpty()) aria-label="{{ $label }}" title="{{ $label }}" @endif
        {{ $attributes->class(['flex items-center gap-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500']) }}
    >
        <flux:icon.github variant="mini" />
        {{ $slot }}
    </a>
@endif
