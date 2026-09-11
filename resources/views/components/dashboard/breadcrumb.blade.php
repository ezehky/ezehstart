{{--
    Where the current page sits, read out of the site title.

        <x-dashboard.breadcrumb />
        <x-dashboard.breadcrumb separator="slash" />
        <x-dashboard.breadcrumb :items="['Users' => route('admin.users'), $user->name => null]" />

    Passing nothing is the normal case: kSetSiteTitle() has already named the page
    and the sidebar tree already knows what each segment links to, so the trail
    builds itself. Pass `items` only where a page sits somewhere the title cannot
    express — an assoc array of label => link, or the list shape kBreadcrumbTrail()
    returns.

    The last crumb is never a link, whichever way the trail arrived.
--}}

@props([
    'items' => null,
    'home' => true,
    'separator' => null,
])

@php
    $trail = collect($items ?? kBreadcrumbTrail())
        ->map(fn ($value, $key) => is_array($value)
            ? $value
            : ['label' => $key, 'link' => $value, 'icon' => null])
        ->values()
        // The page you are on is not somewhere to navigate to. kBreadcrumbTrail()
        // has already done this; an explicitly passed trail has not.
        ->pipe(fn ($crumbs) => $crumbs->map(fn ($crumb, $index) => $index === $crumbs->count() - 1
            ? [...$crumb, 'link' => null]
            : $crumb));

    // The workspace root, as an icon. Dropped on the dashboard itself, where it
    // would be a crumb pointing at the page already on screen.
    $dashboardRoute = str_starts_with((string) request()->route()?->getName(), 'admin.')
        ? 'admin.dashboard'
        : 'user.dashboard';

    $showHome = $home && $trail->isNotEmpty() && ! request()->routeIs($dashboardRoute);
@endphp

@if ($trail->isNotEmpty())
    <flux:breadcrumbs {{ $attributes->merge() }}>
        @if ($showHome)
            <flux:breadcrumbs.item :href="route($dashboardRoute)" icon="home" :separator="$separator" wire:navigate />
        @endif

        @foreach ($trail as $crumb)
            <flux:breadcrumbs.item :href="$crumb['link']" :separator="$separator" wire:navigate>
                {{ $crumb['label'] }}
            </flux:breadcrumbs.item>
        @endforeach
    </flux:breadcrumbs>
@endif
