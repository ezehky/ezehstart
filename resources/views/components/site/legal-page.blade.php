@props([
    'title',
    'intro' => null,
    'version' => null,
    'updatedAt' => null,
    // Compiled by PolicyContentService: id, title (null keeps it out of the
    // contents sidebar) and html. The html is markdown-derived with raw input
    // escaped by MarkdownService, so it is safe to render unescaped.
    'sections' => [],
    // The other policies, linked at the foot of the page.
    'related' => [],
])

@php
    // Only titled sections belong in the contents sidebar — an untitled preamble
    // would be a link with nothing to put on it.
    $contents = collect($sections)->filter(fn (array $section) => filled($section['title'] ?? null));
@endphp

<main id="main" tabindex="-1" class="mx-auto w-full max-w-6xl flex-1 px-6 pb-16">
    <section class="border-b border-slate-200 pb-10 dark:border-white/10">
        <flux:badge size="sm" color="lime" inset="top bottom">Legal</flux:badge>

        <h1 class="mt-4 font-heading text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl dark:text-white">
            {{ $title }}
        </h1>

        @if ($intro)
            <p class="mt-5 max-w-2xl text-lg leading-relaxed text-slate-600 dark:text-slate-400">
                {{ $intro }}
            </p>
        @endif

        @if ($updatedAt || $version)
            <div class="mt-6 flex flex-wrap items-center gap-2.5">
                @if ($updatedAt)
                    <span class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3.5 py-2 text-sm text-slate-500 dark:border-white/10 dark:text-slate-400">
                        <flux:icon name="calendar-days" class="size-4" />
                        Last updated {{ $updatedAt }}
                    </span>
                @endif
                @if ($version)
                    <span class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3.5 py-2 text-sm text-slate-500 dark:border-white/10 dark:text-slate-400">
                        <flux:icon name="document-text" class="size-4" />
                        Version {{ $version }}
                    </span>
                @endif
            </div>
        @endif
    </section>

    <div class="py-12 lg:flex lg:gap-14">
        @if ($contents->isNotEmpty())
            <aside class="lg:sticky lg:top-8 lg:h-fit lg:w-64 lg:shrink-0">
                <nav aria-label="Sections on this page" class="rounded-2xl border border-slate-200 p-5 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">On this page</h2>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($contents as $section)
                            <li>
                                <a
                                    href="#{{ $section['id'] }}"
                                    class="text-sm text-slate-500 transition-colors hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime-500 dark:text-slate-400 dark:hover:text-white"
                                >
                                    {{ $section['title'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>
        @endif

        <article class="mt-10 min-w-0 flex-1 lg:mt-0">
            @if (filled($sections))
                <div class="space-y-12">
                    @foreach ($sections as $section)
                        {{-- scroll-mt keeps a deep-linked heading clear of the top of
                             the viewport rather than flush against it. --}}
                        <section id="{{ $section['id'] }}" class="scroll-mt-8">
                            @if (filled($section['title'] ?? null))
                                <h2 class="font-heading text-xl font-semibold text-slate-900 sm:text-2xl dark:text-white">
                                    {{ $section['title'] }}
                                </h2>
                            @endif

                            <div class="markdown-prose mt-4">
                                {!! $section['html'] !!}
                            </div>
                        </section>
                    @endforeach
                </div>
            @else
                {{-- A legal page with nothing on it is a configuration problem, not a
                     404. Saying so is more use to whoever has to fix it than an
                     empty page would be. --}}
                <x-dashboard.workspace-no-record
                    label="This policy"
                    icon="document-text"
                    text="No version of this policy has been published yet. An administrator can write and publish one from the admin area."
                />
            @endif

            @if (filled($related))
                <div class="mt-16 border-t border-slate-200 pt-8 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Related policies</h2>
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach ($related as $item)
                            <li>
                                <a
                                    href="{{ $item['href'] }}"
                                    class="group flex items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3.5 transition-colors hover:border-lime-300 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime-500 dark:border-white/10 dark:hover:border-lime-400/30 dark:hover:bg-white/5"
                                >
                                    <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $item['label'] }}</span>
                                    <flux:icon name="arrow-right" class="size-4 shrink-0 text-slate-400 transition-transform group-hover:translate-x-0.5" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </article>
    </div>
</main>
