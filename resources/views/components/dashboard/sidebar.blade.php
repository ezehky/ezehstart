@props(['navigationLinks', 'dashboardRoute'])

<aside class="flex h-full w-72 flex-col border-r border-slate-200 bg-accent-foreground px-4 py-5 dark:border-slate-800 dark:bg-slate-950 lg:w-64">
    <div class="shrink-0">
        <flux:brand
            href="{{ $dashboardRoute }}"
            :logo="$_configs['logo'] ?? ''"
            :logoDark="$_configs['logo-dark'] ?? ''"
            alt="{{ $_configs['name'] }} official logo"
        />
    </div>

    <div class="-mr-2 min-h-0 flex-1 overflow-y-auto pr-2 custom-scrollbar">
        <div class="mt-8">
            <p class="px-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Workspace</p>
            <nav class="mt-3 space-y-1" aria-label="Dashboard navigation">
                @foreach ($navigationLinks as $key => $navigationLink)
                    @php($active = kCheckActiveTitle($key))
                    @php($children = $navigationLink['children'] ?? [])

                    @if ($children)
                        <div x-data="{ open: {{ $active ? 'true' : 'false' }} }" class="space-y-1">
                            <button
                                type="button"
                                @click="open = !open"
                                :aria-expanded="open"
                                @class([
                                    'press flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-colors duration-150 ease-out',
                                    'bg-slate-900 text-white shadow-sm dark:bg-lime-400 dark:text-slate-950' => $active,
                                    'text-white hover:bg-slate-700 hover:text-slate-100 dark:text-slate-300 dark:hover:bg-slate-900' => ! $active,
                                ])
                                @if (! $active) :class="open && 'bg-slate-700 text-slate-100 dark:bg-slate-900'" @endif
                            >
                                <flux:icon :name="$navigationLink['icon']" class="size-5" />
                                <span class="flex-1">{{ $navigationLink['label'] }}</span>
                                <flux:icon name="chevron-down" class="size-4 transition-transform duration-150 ease-out-strong" x-bind:class="open && 'rotate-180'" />
                            </button>

                            <div
                                x-cloak
                                x-show="open"
                                x-transition:enter="transition duration-150 ease-out-strong"
                                x-transition:enter-start="-translate-y-1 opacity-0"
                                x-transition:enter-end="translate-y-0 opacity-100"
                                x-transition:leave="transition duration-100 ease-out-strong"
                                x-transition:leave-start="translate-y-0 opacity-100"
                                x-transition:leave-end="-translate-y-1 opacity-0"
                                class="space-y-1 border-l border-lime-700 py-1 pl-4 dark:border-slate-800"
                            >
                                @foreach ($children as $keyChild => $child)
                                    @php($childActive = kCheckActiveTitle($keyChild, false))
                                    <a
                                        href="{{ $child['link'] }}"
                                        @class([
                                            'press flex items-center rounded-md px-3 py-2 text-sm font-medium transition-colors duration-150 ease-out',
                                            'bg-slate-700 text-white dark:bg-lime-200 dark:text-slate-950' => $childActive,
                                            'text-slate-100 hover:bg-slate-400 hover:text-slate-950 dark:text-slate-300 dark:hover:text-slate-100 dark:hover:bg-slate-800' => ! $childActive,
                                        ])
                                        @if ($child['spa']) wire:navigate @endif
                                    >
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <a
                            href="{{ $navigationLink['link'] }}"
                            @class([
                                'press flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 ease-out',
                                'bg-slate-900 text-white shadow-sm dark:bg-lime-400 dark:text-slate-950' => $active,
                                'text-white hover:bg-slate-700 hover:text-slate-100 dark:text-slate-300 dark:hover:bg-slate-900' => ! $active,
                            ])
                            @if ($navigationLink['spa']) wire:navigate @endif
                        >
                            <flux:icon :name="$navigationLink['icon']" class="size-5" />
                            <span>{{ $navigationLink['label'] }}</span>
                        </a>
                    @endif
                @endforeach
            </nav>
        </div>
    </div>

    <div class="mt-4 shrink-0 border-t border-lime-700 pt-4 dark:border-slate-800">
        <div class="flex items-center gap-3 px-2">
            <x-dashboard.avatar :user="auth()->user()" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
            </div>
            <flux:button
                href="{{ route('logout') }}"
                icon="arrow-right-start-on-rectangle"
                size="sm"
                aria-label="Sign out"
                title="Sign out"
            />
        </div>
    </div>
</aside>
