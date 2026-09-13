{{-- The member workspace's phone navigation.

     A bar pinned to the bottom of the screen, which is where a thumb is, rather
     than a hamburger in the opposite corner from it. Rendered only below lg —
     above that the sidebar is already on screen and this would be a second copy
     of the same links.

     Off unless the site turns it on: some installs want the drawer and nothing
     else, and a workspace with both is a workspace with two answers to the same
     question. See user => mobile-floating-menu. --}}
@props(['navigationLinks'])

@php
    // Four destinations plus More is what fits on the narrowest phone without the
    // labels wrapping. Everything past the fourth, and every group that has
    // children rather than a link of its own, goes behind the sheet.
    $destinations = collect($navigationLinks)->filter(fn ($item) => filled($item['link'] ?? null));

    $primary = $destinations->take(4);
    $overflow = collect($navigationLinks)->except($primary->keys()->all());
@endphp

<div
    x-data="{ sheet: false }"
    x-on:keydown.escape.window="sheet = false"
    class="lg:hidden"
>
    {{-- The sheet. Rendered before the bar so the bar keeps the higher stacking
         order and stays tappable while it is open. --}}
    <div x-cloak x-show="sheet" class="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-label="More navigation">
        <div
            x-show="sheet"
            x-transition.opacity
            class="absolute inset-0 bg-slate-950/40"
            x-on:click="sheet = false"
        ></div>

        <div
            x-show="sheet"
            x-transition:enter="transition duration-200 ease-drawer"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition duration-150 ease-out-strong"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            class="absolute inset-x-0 bottom-0 max-h-[70vh] overflow-y-auto rounded-t-2xl border-t border-slate-200 bg-white pb-24 shadow-2xl dark:border-slate-800 dark:bg-slate-900"
        >
            <div class="sticky top-0 flex justify-center bg-white py-3 dark:bg-slate-900">
                <span class="h-1 w-10 rounded-full bg-slate-300 dark:bg-slate-700"></span>
            </div>

            <nav class="space-y-1 px-4 pb-4" aria-label="More destinations">
                @foreach ($overflow as $key => $item)
                    @php($children = $item['children'] ?? [])

                    @if ($children)
                        <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-400 dark:text-slate-500">
                            {{ $item['label'] }}
                        </p>

                        @foreach ($children as $childKey => $child)
                            @php($childActive = kCheckActiveTitle($childKey, false))
                            <a
                                href="{{ $child['link'] }}"
                                @if ($child['spa']) wire:navigate @endif
                                x-on:click="sheet = false"
                                @class([
                                    'press flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 ease-out',
                                    'bg-slate-900 text-white dark:bg-lime-400 dark:text-slate-950' => $childActive,
                                    'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' => ! $childActive,
                                ])
                            >
                                {{ $child['label'] }}
                            </a>
                        @endforeach
                    @else
                        @php($active = kCheckActiveTitle($key))
                        <a
                            href="{{ $item['link'] }}"
                            @if ($item['spa']) wire:navigate @endif
                            x-on:click="sheet = false"
                            @class([
                                'press mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 ease-out',
                                'bg-slate-900 text-white dark:bg-lime-400 dark:text-slate-950' => $active,
                                'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' => ! $active,
                            ])
                        >
                            <flux:icon :name="$item['icon']" class="size-5" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endif
                @endforeach
            </nav>
        </div>
    </div>

    {{-- The bar itself. pb-[env(safe-area-inset-bottom)] keeps it clear of the
         home indicator on a notched phone, where a flush bar is half-tappable. --}}
    <nav
        aria-label="Workspace navigation"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-slate-800 dark:bg-slate-900/95"
    >
        <div class="mx-auto flex max-w-lg items-stretch">
            @foreach ($primary as $key => $item)
                @php($active = kCheckActiveTitle($key))
                <a
                    href="{{ $item['link'] }}"
                    @if ($item['spa']) wire:navigate @endif
                    @class([
                        'press flex flex-1 flex-col items-center gap-1 px-1 py-2.5 text-[0.625rem] font-medium transition-colors duration-150 ease-out',
                        'text-slate-900 dark:text-lime-400' => $active,
                        'text-slate-500 dark:text-slate-400' => ! $active,
                    ])
                    @if ($active) aria-current="page" @endif
                >
                    <flux:icon :name="$item['icon']" class="size-5" />
                    <span class="max-w-full truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach

            @if ($overflow->isNotEmpty())
                <button
                    type="button"
                    x-on:click="sheet = ! sheet"
                    x-bind:aria-expanded="sheet"
                    class="press flex flex-1 flex-col items-center gap-1 px-1 py-2.5 text-[0.625rem] font-medium text-slate-500 transition-colors duration-150 ease-out dark:text-slate-400"
                    x-bind:class="sheet && 'text-slate-900 dark:text-lime-400'"
                >
                    <flux:icon name="ellipsis-horizontal" class="size-5" />
                    <span>More</span>
                </button>
            @endif
        </div>
    </nav>

    {{-- The bar floats over the page, so the last card on a scrolled-to-bottom
         screen would sit underneath it without this. --}}
    <div class="h-16" aria-hidden="true"></div>
</div>
