<x-layouts.base class="min-h-screen">
    <div x-data="{ mobileSidebarOpen: false }" class="min-h-screen bg-slate-50 dark:bg-slate-950">
        <div class="fixed inset-y-0 left-0 z-30 hidden lg:block">
            <x-dashboard.sidebar :$navigationLinks :$dashboardRoute />
        </div>

        <div x-cloak x-show="mobileSidebarOpen" class="relative z-50 lg:hidden" aria-modal="true" role="dialog">
            <div
                x-show="mobileSidebarOpen"
                x-transition.opacity
                class="fixed inset-0 bg-slate-950/40"
                @click="mobileSidebarOpen = false"
            ></div>
            <div
                x-show="mobileSidebarOpen"
                x-transition:enter="transition duration-200 ease-drawer"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition duration-150 ease-out-strong"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="fixed inset-y-0 left-0 w-72 shadow-2xl"
            >
                <x-dashboard.sidebar :$navigationLinks :$dashboardRoute />
            </div>
        </div>

        <div class="min-h-screen lg:pl-64">
            <x-dashboard.top-navigation :current-type="$currentType" />
            <main class="mx-auto w-full max-w-[1600px] px-4 py-7 sm:px-6 lg:px-8">
                @session('status')
                    <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
                @endsession
                @session('error')
                    <flux:callout variant="danger" icon="x-circle" class="mt-6 text-sm">{!! session('error') !!}</flux:callout>
                @endsession

                {{-- A pending deletion is not a toast. It stays on every screen of the
                     workspace until the account holder cancels it or the date arrives,
                     because there is nothing to undo afterwards. Members only: the
                     route lives in the member workspace, and so does the feature. --}}
                @if (auth()->user()?->isUser() && auth()->user()->status->isPendingDeletion() && auth()->user()->deletion_scheduled_at)
                    <flux:callout color="amber" icon="clock" class="mt-6 text-sm">
                        <flux:callout.heading>Your account is scheduled for deletion</flux:callout.heading>
                        <flux:callout.text>
                            Everything goes on {{ auth()->user()->deletion_scheduled_at->format('M d, Y') }}.
                            <flux:link :href="route('user.delete-account')" wire:navigate>Keep my account</flux:link>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{ $slot }}
            </main>

            {{-- The phone navigation, members only and off unless the site turns it
                 on. The admin workspace keeps the drawer: its tree is deeper than a
                 five-slot bar can honestly represent. --}}
            @if ($currentType->isUser() && kSiteFlag('user', 'mobile-floating-menu', false))
                <x-dashboard.mobile-menu :$navigationLinks />
            @endif
        </div>
    </div>
</x-layouts.base>
