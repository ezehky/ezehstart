{{-- The shell for every policy page. Rendered by PolicyPageController, which
     resolves which version is in force and compiles it; everything here is
     presentation. --}}
<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
        <x-site.header />

        <x-site.legal-page
            :$title
            :$intro
            :$version
            :$updatedAt
            :$sections
            :$related
        />

        <x-site.footer />
    </div>
</x-layouts.base>
