@props([
    'active',
    'isUser' => false,

    'title' => null,
    'subtitle' => null,
    'left' => null,
])

@php
$tabs = [];

if ($isUser) {
    $tabs = [
        'profile' => ['label' => 'Profile', 'icon' => 'user-circle', 'route' => route('user.profile')],
        'account-settings' => ['label' => 'Settings', 'icon' => 'adjustments-horizontal', 'route' => route('user.account-settings')],
        'security-settings' => ['label' => 'Security', 'icon' => 'shield-check', 'route' => route('user.security-settings')],
        'download-data' => ['label' => 'My data', 'icon' => 'arrow-down-tray', 'route' => route('user.download-data')],
        'delete-account' => ['label' => 'Delete account', 'icon' => 'trash', 'route' => route('user.delete-account')],
    ];

    $userSettings = auth()->user()->userProfile->settings ?? [];

    // Asked through the service rather than kSiteConfig(): the helper's default
    // fires on any falsy value, so a switch deliberately turned off would read
    // back as on and leave the tab on the screen.
    if (
        ! app(\App\Services\AccountDeletionService::class)->isEnabled() ||
        ! data_get($userSettings, 'can-delete-account', false)
    ) {
        unset($tabs['delete-account']);
    }

    // Same reasoning, and the same helper: the switch closes the route too, so a tab
    // left on the screen would lead somewhere that answers 404.
    if (! app(\App\Services\AccountDataExportService::class)->isEnabled()) {
        unset($tabs['download-data']);
    }
}

@endphp
<div class="space-y-6">
    @if ($title || $subtitle || $left)
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="1" size="xl">{!! $title !!}</flux:heading>
                @if ($subtitle)
                    <flux:text class="mt-1">{!! $subtitle !!}</flux:text>
                @endif
            </div>

            {!! $left !!}
        </div>
    @endif

    <nav
        aria-label="Account sections"
        x-data
        x-init="$nextTick(() => $refs.activeTab?.scrollIntoView({ block: 'nearest', inline: 'nearest' }))"
        class="scrollbar-hover -mx-1 flex gap-1.5 overflow-x-auto pb-1"
    >
        @foreach ($tabs as $key => $tab)
            @php($isActive = $active === $key)

            @if (data_get($tab, 'disabled'))
                <flux:button
                    icon="{{ $tab['icon'] }}"
                    variant="{{ $isActive ? 'primary' : 'ghost' }}"
                    size="sm"
                    class="shrink-0"
                    x-ref="{{ $isActive ? 'activeTab' : null }}"
                    :disabled="! $isActive"
                >
                    {{ $tab['label'] }}
                </flux:button>
            @else
                <flux:button
                    href="{{ $tab['route'] }}"
                    wire:navigate
                    icon="{{ $tab['icon'] }}"
                    variant="{{ $isActive ? 'primary' : 'ghost' }}"
                    size="sm"
                    class="shrink-0"
                    x-ref="{{ $isActive ? 'activeTab' : null }}"
                >
                    {{ $tab['label'] }}
                </flux:button>
            @endif
        @endforeach
    </nav>
</div>
