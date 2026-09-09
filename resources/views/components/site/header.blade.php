{{-- The public site's header. Extracted so the landing page and the legal pages
     cannot drift apart — a policy page that does not look like the site it belongs
     to reads as somebody else's. --}}
<header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-6">
    <flux:brand
        href="{{ route('home') }}"
        :logo="$_configs['logo'] ?? ''"
        :logoDark="$_configs['logo-dark'] ?? ''"
        :name="$_configs['name']"
        alt="{{ $_configs['name'] }} official logo"
    />

    <div class="flex items-center gap-2">
        <flux:switch x-data x-model="$flux.dark" />

        @auth
            <flux:button
                :href="auth()->user()->isAdmin() ? route('admin.dashboard') : route('user.dashboard')"
                variant="primary"
                size="sm"
                wire:navigate
            >
                Dashboard
            </flux:button>
        @else
            <flux:button :href="route('login')" variant="ghost" size="sm" wire:navigate>Sign in</flux:button>
            <flux:button :href="route('register')" variant="primary" size="sm" wire:navigate>Get started</flux:button>
        @endauth
    </div>
</header>
