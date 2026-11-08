{{--
    A link to the documentation — beside the repository link in the public header,
    the public footer and the dashboard sidebar.

        <x-util.documentation-link class="…" />                    icon only
        <x-util.documentation-link class="…">Docs</x-util.documentation-link>

    The address is `app.documentation` (APP_DOCUMENTATION_URL). It points at the
    kit's own docs; a project sets its own, or sets it empty, and every link goes
    away together. Nothing renders when it is blank.

    label    the accessible name when there is no slot text to give one.
--}}
@props([
    'href' => config('app.documentation'),
    'label' => __('Read the documentation'),
])

@if (filled($href))
    <a
        href="{{ $href }}"
        target="_blank"
        rel="noopener noreferrer"
        @if ($slot->isEmpty()) aria-label="{{ $label }}" title="{{ $label }}" @endif
        {{ $attributes->class(['flex items-center gap-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500']) }}
    >
        <flux:icon.book-open variant="mini" />
        {{ $slot }}
    </a>
@endif
