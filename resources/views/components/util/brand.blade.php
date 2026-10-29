{{--
    The site's logo, linked — the header, the sidebar and the sign-in screens.

        <x-util.brand />
        <x-util.brand :href="$dashboardRoute" />
        <x-util.brand size="max-w-24" />

    Both logos arrive resolved from the site configuration: the uploaded file, the
    light one standing in for a dark one that was never uploaded, and the kit's own
    artwork when neither was. The component only decides which one the current
    theme shows.

    size     the width cap on the image; any max-w-* utility. max-w-16 unless told.
--}}
@props([
    'href' => route('home'),
    'logo' => $_configs['logo'] ?? '',
    'logoDark' => $_configs['logo-dark'] ?? '',
    'alt' => ($_configs['name'] ?? config('app.name')).' official logo',
    'size' => 'max-w-16',
])

<a
    href="{{ $href }}"
    {{ $attributes->class(['inline-flex shrink-0 items-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-500']) }}
    data-flux-brand
>
    <img src="{{ $logo }}" alt="{{ $alt }}" @class(['block h-auto w-auto', $size, 'dark:hidden' => $logoDark]) />

    @if ($logoDark)
        <img src="{{ $logoDark }}" alt="{{ $alt }}" @class(['hidden h-auto w-auto dark:block', $size]) />
    @endif
</a>
