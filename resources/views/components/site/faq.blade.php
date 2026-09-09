@props([
    // App\Models\Faq rows, already filtered and ordered by the caller. The answer
    // is compiled through answerHtml(), which escapes raw input, so it is safe to
    // render unescaped.
    'faqs' => [],
])

@if (filled($faqs))
    <section id="faq" class="mx-auto w-full max-w-6xl scroll-mt-8 border-t border-slate-200 px-6 py-16 dark:border-white/10">
        <div class="mx-auto max-w-3xl">
            <h2 class="font-heading text-3xl font-bold tracking-tight text-slate-950 dark:text-white">
                Frequently asked questions
            </h2>
            <flux:text class="mt-3">
                The things people ask before they sign up. If yours is not here, get in touch.
            </flux:text>

            {{-- One panel open at a time, and the first one open on arrival: a wall
                 of closed rows makes people click before they can tell whether the
                 page holds anything they need. --}}
            <div
                x-data="{ active: 0 }"
                class="mt-10 divide-y divide-slate-200 overflow-hidden rounded-2xl border border-slate-200 dark:divide-white/10 dark:border-white/10"
            >
                @foreach ($faqs as $index => $faq)
                    <div>
                        <h3>
                            <button
                                type="button"
                                x-on:click="active = active === {{ $index }} ? null : {{ $index }}"
                                x-bind:aria-expanded="(active === {{ $index }}).toString()"
                                aria-controls="faq-panel-{{ $index }}"
                                class="flex w-full items-center justify-between gap-4 px-6 py-5 text-left transition-colors hover:bg-slate-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-lime-500 dark:hover:bg-white/5"
                            >
                                <span class="font-heading text-base font-semibold text-slate-900 dark:text-white">
                                    {{ $faq->question }}
                                </span>
                                <span
                                    class="grid size-8 shrink-0 place-items-center rounded-full border border-slate-200 text-slate-500 transition-transform duration-300 dark:border-white/10 dark:text-slate-300"
                                    x-bind:class="active === {{ $index }} ? 'rotate-45 border-lime-400 text-lime-600 dark:text-lime-400' : ''"
                                >
                                    <flux:icon name="plus" class="size-4" />
                                </span>
                            </button>
                        </h3>

                        <div id="faq-panel-{{ $index }}" x-show="active === {{ $index }}" x-collapse x-cloak>
                            <div class="markdown-prose px-6 pb-5">
                                {!! $faq->answerHtml() !!}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
