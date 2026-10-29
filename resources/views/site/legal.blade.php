{{-- The shell for every policy page. Rendered by PolicyPageController, which
     resolves which version is in force and compiles it; everything here is
     presentation. --}}
<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
        <x-site.header />

        {{-- Not <x-layouts.site>: the legal page is its own <main>, with the skip
             link's target on it, and a main inside a main is not allowed. The
             wrapper carries the entrance the layout gives every other page. --}}
        <div class="page-enter flex flex-1 flex-col">
            <x-site.legal-page
                :$title
                :$intro
                :$version
                :$updatedAt
                :$sections
                :$related
            />
        </div>

        <x-site.footer />
    </div>
</x-layouts.base>
