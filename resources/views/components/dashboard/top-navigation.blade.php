@props(['currentType'])

{{--
    The backdrop blur makes this header a stacking context, so anything opening out
    of it — the notification panel, the appearance menu — is capped at the header's
    own level no matter how high its z-index goes. z-40 lifts the whole header above
    the fixed sidebar (z-30) while staying under the mobile drawer (z-50).
--}}
<header class="relative z-40 flex h-16 items-center justify-between gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/90 sm:px-6">
    <div class="flex min-w-0 items-center gap-3">
        <button type="button" class="press rounded-md p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900 lg:hidden" @click="mobileSidebarOpen = true" aria-label="Open navigation">
            <flux:icon name="bars-3" class="size-5" />
        </button>
        <nav class="hidden items-center gap-2 text-sm sm:flex" aria-label="Breadcrumb">
            <span class="text-slate-500 dark:text-slate-400">{{ $currentType->label() }}</span>
            <flux:icon name="chevron-right" class="size-4 text-slate-400" />
            <span class="font-medium text-slate-950 dark:text-white">{{ config('_setups.title') }}</span>
        </nav>
    </div>

    <div class="flex items-center gap-1 sm:gap-2">
        {{-- <label class="hidden items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 md:flex">
            <flux:icon name="magnifying-glass" class="size-4" />
            <input type="search" class="w-36 border-0 bg-transparent p-0 text-sm text-slate-950 outline-none placeholder:text-slate-400 focus:ring-0 dark:text-white" placeholder="Search" aria-label="Search" />
            <kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-400 dark:border-slate-700 dark:bg-slate-800">/</kbd>
        </label> --}}
        <livewire:livewire.notifications />
        <flux:dropdown x-data align="end">
            <flux:button variant="subtle" square class="group" aria-label="Preferred color scheme">
                <flux:icon.sun x-show="$flux.appearance === 'light'" variant="mini" class="text-zinc-500 dark:text-white" />
                <flux:icon.moon x-show="$flux.appearance === 'dark'" variant="mini" class="text-zinc-500 dark:text-white" />
                <flux:icon.moon x-show="$flux.appearance === 'system' && $flux.dark" variant="mini" />
                <flux:icon.sun x-show="$flux.appearance === 'system' && ! $flux.dark" variant="mini" />
            </flux:button>

            <flux:menu>
                <flux:menu.item icon="sun" x-on:click="$flux.appearance = 'light'">Light</flux:menu.item>
                <flux:menu.item icon="moon" x-on:click="$flux.appearance = 'dark'">Dark</flux:menu.item>
                <flux:menu.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">System</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>
</header>
