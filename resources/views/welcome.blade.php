@php
    kSetSiteTitle('home');
@endphp

<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
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

        <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center px-6 py-16">
            <div class="max-w-2xl">
                <flux:text color="lime" class="font-semibold">Starter kit</flux:text>
                <h1 class="mt-3 font-heading text-4xl font-bold tracking-tight sm:text-5xl dark:text-white">
                    {{ $_configs['name'] }}
                </h1>
                <flux:text class="mt-5 text-lg dark:text-slate-400">
                    Authentication, roles, two workspaces and account management — already wired,
                    already tested. Replace this page and start building the part that is yours.
                </flux:text>

                <div class="mt-8 flex flex-wrap gap-3">
                    @guest
                        <flux:button :href="route('register')" variant="primary" icon="arrow-right" wire:navigate>
                            Create an account
                        </flux:button>
                        <flux:button :href="route('passwordless')" icon="envelope" wire:navigate>
                            Sign in with an email code
                        </flux:button>
                    @endguest
                </div>
            </div>

            <div class="mt-16 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['icon' => 'lock-closed', 'title' => 'Auth, four ways', 'body' => 'Password, passwordless codes, email verification and reset.'],
                    ['icon' => 'users', 'title' => 'Roles and workspaces', 'body' => 'Admin and member, each behind its own middleware and route file.'],
                    ['icon' => 'clock', 'title' => 'Audit trail', 'body' => 'Every admin write records what changed, and what it changed from.'],
                    ['icon' => 'cog-6-tooth', 'title' => 'Site configuration', 'body' => 'Editable settings with sensible defaults, no deploy required.'],
                ] as $feature)
                    <flux:card class="space-y-3">
                        <x-dashboard.icon-box size="sm" :icon="$feature['icon']" tone="emerald" />
                        <flux:heading size="lg">{{ $feature['title'] }}</flux:heading>
                        <flux:text class="text-sm">{{ $feature['body'] }}</flux:text>
                    </flux:card>
                @endforeach
            </div>
        </main>

        <footer class="mx-auto w-full max-w-6xl px-6 py-8">
            <flux:separator variant="subtle" />
            <flux:text class="mt-6 text-sm">
                &copy; {{ now()->year }} {{ $_configs['name'] }}.
            </flux:text>
        </footer>
    </div>
</x-layouts.base>
