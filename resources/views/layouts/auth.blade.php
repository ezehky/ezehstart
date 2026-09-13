<x-layouts.base class="min-h-screen dark:bg-slate-950 dark:text-slate-100">
    <main class="grid min-h-screen lg:grid-cols-[1.05fr_0.95fr]">
        <section class="flex items-center justify-center px-5 py-10 sm:px-8 lg:px-16">
            <div class="w-full max-w-md">
                <flux:brand
                    href="{{ route('home') }}"
                    :logo="$_configs['logo'] ?? ''"
                    :logoDark="$_configs['logo-dark'] ?? ''"
                    alt="{{ $_configs['name'] }} official logo"
                />

                <div class="mt-10">
                    @isset($tag)
                        <flux:text color="lime" class="font-semibold">{!! $tag !!}</flux:text>
                    @endisset
                    @isset($title)
                        <h2 class="mt-2 font-heading text-3xl font-bold dark:text-white">{!! $title !!}</h2>
                    @endisset
                    @isset($description)
                        <flux:text class="mt-3 dark:text-slate-400">{!! $description !!}</flux:text>
                    @endisset
                    @session('status')
                        <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
                    @endsession

                    {{ $slot }}

                    @isset($extra)
                        {!! $extra !!}
                    @endisset
                </div>
            </div>
        </section>

        <aside class="relative hidden overflow-hidden bg-lime-400 p-16 text-slate-950 lg:flex lg:flex-col lg:justify-between">
            <div
                class="absolute inset-0 opacity-20"
                style="background-image: radial-gradient(circle at 1px 1px, currentColor 1px, transparent 0); background-size: 22px 22px;"
            ></div>
            <div class="relative max-w-md">
                <p class="text-sm font-bold uppercase tracking-[0.18em]">{{ $_configs['name'] }}</p>
                <h1 class="mt-6 font-heading text-5xl font-bold leading-tight">Everything you need, on the first commit.</h1>
            </div>
            <div class="relative flex items-center gap-3 border-t border-slate-950/20 pt-6 text-sm font-medium">
                <flux:icon.check-badge class="size-6" />
                Replace this panel with your own pitch.
                <flux:switch x-data x-model="$flux.dark"  />
            </div>
        </aside>
    </main>
</x-layouts.base>
