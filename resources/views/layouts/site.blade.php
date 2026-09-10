{{--
    The public shell: header, content, footer.

    The third layout alongside `layouts::app` (the signed-in workspaces) and
    `layouts::auth` (the sign-in screens). It exists because a public Livewire
    page had nowhere to render — `layouts::app` wants a sidebar and the
    navigation links that go with it, which a visitor has none of.

    Used as `#[Layout('layouts::site')]` on any public SFC.
--}}
<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
        <x-site.header />

        <main class="flex-1">
            {{ $slot }}
        </main>

        <x-site.footer />
    </div>
</x-layouts.base>
