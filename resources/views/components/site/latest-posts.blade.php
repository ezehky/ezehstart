{{-- The three newest posts, for the front page.

     A teaser rather than a second blog index: the point is to show the site is
     alive and send somebody to /blog, so there is no pagination, no filtering and
     no search here — that screen already does all three.

     Silent when nothing is published, the same way the FAQ section is. An untouched
     starter kit should not show an empty "Latest posts" heading. --}}
@props(['limit' => 3])

@php($posts = app(App\Services\BlogService::class)->publishedQuery()->limit($limit)->get())

@if ($posts->isNotEmpty())
    <section class="mx-auto w-full max-w-6xl px-6 py-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:text color="lime" class="font-semibold">From the blog</flux:text>
                <h2 class="mt-2 font-heading text-3xl font-bold tracking-tight dark:text-white">
                    Latest posts
                </h2>
            </div>

            <flux:button href="{{ route('blog.index') }}" variant="ghost" icon:trailing="arrow-right">
                Read everything
            </flux:button>
        </div>

        <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($posts as $post)
                <article class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                    <a href="{{ route('blog.show', $post) }}" wire:navigate class="block aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
                        <img
                            src="{{ $post->image?->url() ?? kSafeImage() }}"
                            alt="{{ $post->image?->alt_text ?: $post->title }}"
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                            loading="lazy"
                        />
                    </a>

                    <div class="flex grow flex-col gap-3 p-5">
                        <flux:heading level="3" size="lg">
                            <a href="{{ route('blog.show', $post) }}" wire:navigate class="hover:underline">
                                {{ $post->title }}
                            </a>
                        </flux:heading>

                        <flux:text class="grow">{{ Str::limit($post->excerpt, 120) }}</flux:text>

                        <flux:text size="sm" class="text-slate-500 dark:text-slate-400">
                            {{ $post->authorName() }} · {{ $post->publishedHuman() }} · {{ $post->readTime() }}
                        </flux:text>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
