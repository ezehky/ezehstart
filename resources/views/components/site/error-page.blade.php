{{-- The shape every error page takes.

     One component rather than six near-identical views: an error page is a status
     code, a sentence and a way out, and the only thing that varies between them is
     the wording. The header and footer come along because somebody who hit a dead
     link still wants the rest of the site.

     Deliberately no Livewire and no queries. These render when something has already
     gone wrong, and a 500 page that needs the database to draw itself is a 500 page
     that will not draw. --}}
@props([
    'code' => '',
    'title' => 'Something went wrong',
    'message' => '',
])

@php(kSetSiteTitle($code ? $code.' — '.$title : $title))

<x-layouts.base class="min-h-screen">
    <div class="flex min-h-screen flex-col">
        <x-site.header />

        <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center px-6 py-16">
            @if ($code)
                <p class="font-heading text-7xl font-bold tracking-tight text-lime-600 sm:text-8xl dark:text-lime-400">
                    {{ $code }}
                </p>
            @endif

            <h1 class="mt-4 font-heading text-3xl font-bold tracking-tight sm:text-4xl dark:text-white">
                {{ $title }}
            </h1>

            @if ($message)
                <flux:text class="mt-4 max-w-xl text-lg dark:text-slate-400">
                    {{ $message }}
                </flux:text>
            @endif

            <div class="mt-8 flex flex-wrap gap-3">
                <flux:button href="{{ route('home') }}" variant="primary" icon="home">
                    Back to the site
                </flux:button>

                {{-- A link rather than history.back(): the page they came from is
                     often the one that just failed. --}}
                <flux:button href="{{ route('blog.index') }}" variant="ghost">
                    Read the blog
                </flux:button>
            </div>
        </main>

        <x-site.footer />
    </div>
</x-layouts.base>
