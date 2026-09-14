{{-- The legal links are built from the enum rather than written out, so publishing
     a new policy type puts it in the footer of every public page without anyone
     remembering to come back here. --}}
@php($policies = App\Enums\PolicyTypeEnum::cases())

<footer class="mx-auto w-full max-w-6xl px-6 py-8">
    <x-site.newsletter />

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

{{-- Rendered from here for the same reason the legal links are built from the enum:
     the footer is the one block every public shell includes, so a popup placed here
     reaches the home page, the blog and the legal pages without three edits — and
     stays out of the two signed-in workspaces, which do not render a footer. It is
     fixed to the viewport, so sitting at the end of the document costs it nothing. --}}
<x-site.newsletter-popup />
