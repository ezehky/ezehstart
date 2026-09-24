{{-- A row of figures that count up as they scroll into view, for the front page.

        <x-site.stats :stats="$this->stats" />

     Presentation only: the figures come from SiteStatsService::frontPage(), read
     by the page. Silent when handed nothing, which is what the service hands an
     install nobody has signed up to yet. --}}
@props([
    // [{to, label, plus, icon}], in the order they are shown.
    'stats' => [],
])

@if (filled($stats))
    <section class="mx-auto w-full max-w-6xl px-6 py-16">
        <div class="grid grid-cols-2 justify-items-center gap-8 rounded-2xl border border-slate-200 bg-white p-8 sm:p-10 lg:grid-cols-4 dark:border-slate-800 dark:bg-slate-900">
            @foreach ($stats as $stat)
                <x-util.counter
                    :to="$stat['to']"
                    :plus="$stat['plus']"
                    :label="$stat['label']"
                    :icon="$stat['icon']"
                    align="center"
                    size="md"
                />
            @endforeach
        </div>
    </section>
@endif
