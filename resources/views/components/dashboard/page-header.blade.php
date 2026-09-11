{{--
    The block every dashboard screen opens with: where you are, what the page is
    for, and what you can do on it.

        <x-dashboard.page-header />

        <x-dashboard.page-header subtitle="Every account signed up to the member workspace.">
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create">Add member</flux:button>
            </x-slot:actions>
        </x-dashboard.page-header>

    Passing nothing is the normal case. kSetSiteTitle() in mount() has already named
    the page and may have carried a subtitle and a back link with it; this is the
    component that finally renders them. Pass `title` or `subtitle` only to override
    what the title carries.

    kPauseSiteTitle() suppresses the heading and the trail but not the actions — a
    page that draws its own title still wants its buttons in the usual place.
--}}

@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'breadcrumb' => true,
    'back' => null,
    'actions' => null,
])

@php
    $segments = kDestructSiteTitle();

    // The deepest segment, not the whole trail — the crumbs above already say
    // where this sits, and repeating them in a page title reads as a file path.
    $title ??= $segments ? end($segments) : null;

    $subtitle ??= config('_setups.subtitle');

    // false turns the back link off on a page whose title set one; null means
    // "whatever kBackForwardLink() left behind", which is usually nothing.
    $back = $back === false ? null : ($back ?? config('_setups.back-forward-link'));

    $showTitle = config('_setups.show-title', true);

    // kBackForwardLink() can turn SPA navigation off for a link that leaves the
    // Livewire page tree. Carried as a bag and merged with :attributes because a
    // bare @if cannot live in a component tag's attribute list.
    $backAttributes = new \Illuminate\View\ComponentAttributeBag(
        ($back['spa'] ?? true) ? ['wire:navigate' => true] : []
    );
@endphp

<div {{ $attributes->class('space-y-4')->merge() }}>
    @if ($showTitle && $breadcrumb)
        <x-dashboard.breadcrumb />
    @endif

    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        @if ($showTitle && ($title || $subtitle))
            <div class="flex items-start gap-3">
                @if ($icon)
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <flux:icon :name="$icon" class="size-5" />
                    </span>
                @endif

                <div class="min-w-0">
                    @if ($title)
                        <flux:heading level="1" size="xl" class="font-heading">{!! $title !!}</flux:heading>
                    @endif

                    @if ($subtitle)
                        <flux:text class="mt-1">{!! $subtitle !!}</flux:text>
                    @endif
                </div>
            </div>
        @endif

        @if ($back || $actions)
            <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                @if ($back)
                    {{-- A forward link points on rather than back, so its arrow
                         trails the label instead of leading it. --}}
                    @if ($back['forward'] ?? false)
                        <flux:button :href="$back['route']" icon:trailing="arrow-right" variant="ghost" size="sm" :attributes="$backAttributes">
                            {{ $back['label'] ?? 'Back' }}
                        </flux:button>
                    @else
                        <flux:button :href="$back['route']" icon="arrow-left" variant="ghost" size="sm" :attributes="$backAttributes">
                            {{ $back['label'] ?? 'Back' }}
                        </flux:button>
                    @endif
                @endif

                {!! $actions !!}
            </div>
        @endif
    </div>
</div>
