{{--
    The public shell for a controller-rendered page: header, content, footer.

    The component form of `layouts::site`, which the interactive public pages use
    through #[Layout]. A page under resources/views/site/ wraps itself in this:

        <x-layouts.site>
            …
        </x-layouts.site>

    The two files must stay identical below this comment — change one, change both.
    A visitor moving between a read-only page and a form should not see the frame
    shift under them.
--}}
<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
        <x-site.header />

        <main class="page-enter flex-1">
            {{ $slot }}
        </main>

        <x-site.footer />
    </div>
</x-layouts.base>
