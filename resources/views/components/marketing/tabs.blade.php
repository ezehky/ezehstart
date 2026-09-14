{{--
    The five-tab strip every Email Marketing screen opens with.

        <x-marketing.tabs active="campaigns" />
--}}

@props(['active'])

@php
    $tabs = [
        'campaigns' => ['label' => 'Campaigns', 'icon' => 'rectangle-stack', 'route' => route('admin.marketing.campaigns')],
        'templates' => ['label' => 'Templates', 'icon' => 'document-duplicate', 'route' => route('admin.marketing.templates')],
        'sections' => ['label' => 'Saved Sections', 'icon' => 'squares-2x2', 'route' => route('admin.marketing.sections')],
        'sent' => ['label' => 'Sent', 'icon' => 'paper-airplane', 'route' => route('admin.marketing.sent')],
        'settings' => ['label' => 'Settings', 'icon' => 'cog-6-tooth', 'route' => route('admin.marketing.settings')],
    ];
@endphp

<nav aria-label="Email Marketing sections" class="scrollbar-hover -mx-1 flex gap-1.5 overflow-x-auto pb-1">
    @foreach ($tabs as $key => $tab)
        <flux:button
            href="{{ $tab['route'] }}"
            wire:navigate
            icon="{{ $tab['icon'] }}"
            variant="{{ $active === $key ? 'primary' : 'ghost' }}"
            size="sm"
            class="shrink-0"
        >
            {{ $tab['label'] }}
        </flux:button>
    @endforeach
</nav>
