{{--
    The public shell: header, content, footer.

    The third layout alongside `layouts::app` (the signed-in workspaces) and
    `layouts::auth` (the sign-in screens), for the public pages a visitor fills
    in — unsubscribe today. `layouts::app` wants a sidebar and the navigation
    links that go with it, which a visitor has none of.

    Used as `#[Layout('layouts::site')]` on a public SFC. A read-only public page
    is a controller and uses the component form, `<x-layouts.site>`. The two files
    must stay identical below this comment — change one, change both.
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
