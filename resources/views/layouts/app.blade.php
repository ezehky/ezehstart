<x-layouts.base class="min-h-screen">
    <div x-data="{ mobileSidebarOpen: false }" class="min-h-screen bg-slate-50 dark:bg-slate-950">
        <div class="fixed inset-y-0 left-0 z-30 hidden lg:block">
            <x-dashboard.sidebar :$navigationLinks :$dashboardLinks :$currentRole :$dashboardRoute />
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
                <x-dashboard.sidebar :$navigationLinks :$dashboardLinks :$currentRole :$dashboardRoute />
            </div>
        </div>

        <div class="min-h-screen lg:pl-64">
            <x-dashboard.top-navigation :current-role="$currentRole" />
            <main class="mx-auto w-full max-w-[1600px] px-4 py-7 sm:px-6 lg:px-8">
                @session('status')
                    <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
                @endsession
                @session('error')
                    <flux:callout variant="danger" icon="x-circle" class="mt-6 text-sm">{!! session('error') !!}</flux:callout>
                @endsession

                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
