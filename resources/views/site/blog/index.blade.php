{{-- The post list, rendered by Site\BlogController::index(). Filters are query
     strings — links and a GET form — so a filtered list can be shared. --}}
<x-layouts.site>
    <div class="mx-auto max-w-6xl space-y-8 px-4 py-12">
        <div class="text-center">
            <flux:heading level="1" size="xl">
                {{ $activeCategory?->name ?? __('Blog') }}
            </flux:heading>
            <flux:text class="mx-auto mt-2 max-w-2xl">
                {{ $activeCategory?->description ?? __('News, notes and the occasional long read.') }}
            </flux:text>
        </div>

        {{-- A category link keeps the search and drops the page: a new filter starts
             from the first page. --}}
        <nav class="flex flex-wrap items-center justify-center gap-2" aria-label="{{ __('Categories') }}">
            <flux:button
                size="sm"
                variant="{{ $category === null ? 'primary' : 'ghost' }}"
                :href="route('blog.index', array_filter(['search' => $search, 'tag' => $tag]))"
            >
                {{ __('All') }}
            </flux:button>
            @foreach ($categories as $item)
                <flux:button
                    size="sm"
                    variant="{{ $category === $item->slug ? 'primary' : 'ghost' }}"
                    :href="route('blog.index', array_filter(['category' => $item->slug, 'search' => $search, 'tag' => $tag]))"
                >
                    {{ $item->name }}
                </flux:button>
            @endforeach
        </nav>

        {{-- Enter submits. The category and tag ride along as hidden fields so a
             search inside a filter stays inside it. --}}
        <form method="GET" action="{{ route('blog.index') }}" class="mx-auto max-w-md" role="search">
            @if ($category)
                <input type="hidden" name="category" value="{{ $category }}">
            @endif
            @if ($tag)
                <input type="hidden" name="tag" value="{{ $tag }}">
            @endif
            <flux:input
                name="search"
                :value="$search"
                :placeholder="__('e.g. Getting started')"
                :aria-label="__('Search the blog')"
                icon="magnifying-glass"
            />
        </form>

        @if ($activeTag)
            <div class="flex items-center justify-center gap-2">
                <flux:text size="sm">{{ __('Tagged') }}</flux:text>
                <flux:badge size="sm">{{ $activeTag->name }}</flux:badge>
                <flux:button size="xs" variant="ghost" :href="route('blog.index')">{{ __('Clear') }}</flux:button>
            </div>
        @endif

        @if ($posts->isEmpty())
            <x-dashboard.workspace-no-record
                icon="newspaper"
                :label="__('Nothing to read yet')"
                :text="__('There are no posts matching this just now. Try another category.')"
            />
        @else
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <article class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <a href="{{ route('blog.show', $post) }}" class="block aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
                            <img
                                src="{{ $post->image?->url() ?? kSafeImage() }}"
                                alt="{{ $post->image?->alt_text ?: $post->title }}"
                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                        </a>

                        <div class="flex grow flex-col gap-3 p-5">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($post->categories as $postCategory)
                                    <flux:badge size="sm" inset="top bottom">{{ $postCategory->name }}</flux:badge>
                                @endforeach
                            </div>

                            <flux:heading level="2" size="lg">
                                <a href="{{ route('blog.show', $post) }}" class="hover:underline">
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

            <div>{{ $posts->links() }}</div>
        @endif
    </div>
</x-layouts.site>
