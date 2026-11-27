{{--
    The tab bar shared by the two halves of Configuration › Email settings.

        <x-dashboard.email-settings-tabs active="sender" />

    Each tab is its own route and its own form, so a save on one never carries
    the other's unsaved edits with it. The bar is links under wire:navigate, which
    keeps a tab bookmarkable and the switch as quick as an in-page one. Both share
    the `config.email-settings` gate, so there is nothing to hide per tab.

    The page names its own tab rather than the bar asking the route: after a
    Livewire round trip the request is livewire/update, and a bar that asked
    would lose its highlight on the first button pressed.
--}}

@props(['active'])

@php
    $tabs = [
        'sender' => ['label' => 'Sender', 'icon' => 'at-symbol', 'route' => route('admin.config.email-settings.sender')],
        'delivery' => ['label' => 'Delivery', 'icon' => 'paper-airplane', 'route' => route('admin.config.email-settings.delivery')],
    ];
@endphp

<nav aria-label="Email settings" class="scrollbar-hover -mx-1 flex gap-1.5 overflow-x-auto pb-1">
    @foreach ($tabs as $key => $tab)
        @php($isActive = $active === $key)

        <flux:button
            href="{{ $tab['route'] }}"
            wire:navigate
            icon="{{ $tab['icon'] }}"
            variant="{{ $isActive ? 'primary' : 'ghost' }}"
            size="sm"
            class="shrink-0"
            :aria-current="$isActive ? 'page' : null"
        >
            {{ $tab['label'] }}
        </flux:button>
    @endforeach
</nav>
