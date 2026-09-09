{{-- The legal links are built from the enum rather than written out, so publishing
     a new policy type puts it in the footer of every public page without anyone
     remembering to come back here. --}}
@php($policies = App\Enums\PolicyTypeEnum::cases())

<footer class="mx-auto w-full max-w-6xl px-6 py-8">
    <flux:separator variant="subtle" />

    <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:text class="text-sm">
            &copy; {{ now()->year }} {{ $_configs['name'] }}.
        </flux:text>

        <nav aria-label="Legal" class="flex flex-wrap items-center gap-x-5 gap-y-2">
            @foreach ($policies as $policy)
                <a
                    href="{{ $policy->url() }}"
                    class="text-sm text-slate-500 transition-colors hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime-500 dark:text-slate-400 dark:hover:text-white"
                >
                    {{ $policy->defaultTitle() }}
                </a>
            @endforeach
        </nav>
    </div>
</footer>
