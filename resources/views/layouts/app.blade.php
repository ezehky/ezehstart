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
            {{-- Impersonation. Deliberately above the chrome and in a colour nothing else
                 in the app uses: the failure mode of this feature is an administrator
                 forgetting which account they are in, so the notice does not scroll away
                 and is not a toast. It sits outside <main> so it survives every screen. --}}
            @php($impersonation = app(\App\Services\ImpersonationService::class))
            @if ($impersonation->isImpersonating())
                <div class="sticky top-0 z-40 flex flex-col gap-2 border-b border-amber-500/40 bg-amber-500 px-4 py-2.5 text-sm text-amber-950 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <div class="flex items-start gap-2">
                        <flux:icon icon="eye" variant="mini" class="mt-0.5 shrink-0" />
                        <span>
                            You are viewing the site as <strong>{{ auth()->user()?->name }}</strong>.
                            Anything you do here is recorded against
                            {{ $impersonation->impersonator()?->name ?? 'your account' }}.
                            <span class="whitespace-nowrap">Ends in {{ $impersonation->minutesRemaining() }} min.</span>
                        </span>
                    </div>

                    {{-- A plain link, not wire:navigate: the route swaps the session's
                         identity, and a SPA visit would leave the page it came from
                         rendered for an account that is no longer signed in. --}}
                    <a
                        href="{{ route('impersonation.stop') }}"
                        class="shrink-0 self-start rounded-md bg-amber-950 px-3 py-1.5 font-medium text-amber-50 hover:bg-amber-900 sm:self-auto"
                    >
                        Return to my account
                    </a>
                </div>
            @endif

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
