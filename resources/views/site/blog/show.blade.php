{{-- One post, rendered by Site\BlogController::show(). --}}
<x-layouts.site>
    <div class="mx-auto max-w-3xl px-4 py-12">
        <article class="space-y-6">
            <div class="space-y-3">
                <div class="flex flex-wrap gap-1">
                    @foreach ($post->categories as $category)
                        <a href="{{ route('blog.index', ['category' => $category->slug]) }}">
                            <flux:badge size="sm" inset="top bottom">{{ $category->name }}</flux:badge>
                        </a>
                    @endforeach
                </div>

                <flux:heading level="1" size="xl">{{ $post->title }}</flux:heading>

                <flux:text class="text-slate-500 dark:text-slate-400">
                    {{ $post->authorName() }} · {{ $post->publishedHuman() }} · {{ $post->readTime() }}
                </flux:text>
            </div>

            @if ($post->image)
                <img
                    src="{{ $post->image->url() }}"
                    alt="{{ $post->image->alt_text ?: $post->title }}"
                    class="aspect-video w-full rounded-xl object-cover"
                />
            @endif

            {{-- The body is sanitised on the way in by BlogService::sanitize(), which
                 is what makes rendering it unescaped here safe. --}}
            <div class="rich-prose max-w-none">
                {!! $post->content !!}
            </div>

            @if ($post->tags->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-6 dark:border-slate-800">
                    <flux:text size="sm">{{ __('Tagged') }}</flux:text>
                    @foreach ($post->tags as $tag)
                        <a href="{{ route('blog.index', ['tag' => $tag->slug]) }}">
                            <flux:badge size="sm" inset="top bottom">{{ $tag->name }}</flux:badge>
                        </a>
                    @endforeach
                </div>
            @endif
        </article>

        <x-site.author-card :user="$post->user" />

        @if ($related->isNotEmpty())
            <section class="mt-12 space-y-4">
                <flux:heading level="2" size="lg">{{ __('Read next') }}</flux:heading>

                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ($related as $item)
                        <a
                            href="{{ route('blog.show', $item) }}"
                            class="group space-y-2"
                        >
                            <div class="aspect-video overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                                <img
                                    src="{{ $item->image?->url() ?? kSafeImage() }}"
                                    alt="{{ $item->image?->alt_text ?: $item->title }}"
                                    class="h-full w-full object-cover transition group-hover:scale-105"
                                    loading="lazy"
                                />
                            </div>
                            <p class="text-sm font-semibold text-slate-950 group-hover:underline dark:text-white">
                                {{ $item->title }}
                            </p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.site>
