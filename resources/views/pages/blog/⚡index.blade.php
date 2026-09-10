<?php

use App\Enums\CategoryGroupEnum;
use App\Models\Category;
use App\Models\Tag;
use App\Services\BlogService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::site')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $category = null;

    #[Url]
    public ?string $tag = null;

    public function mount(): void
    {
        kSetSiteTitle('blog');
    }

    #[Computed]
    public function posts()
    {
        return app(BlogService::class)
            ->publishedQuery($this->activeCategory, $this->activeTag, $this->search ?: null)
            ->paginate(9);
    }

    #[Computed]
    public function activeCategory(): ?Category
    {
        return $this->category
            ? Category::query()->active()->inGroup(CategoryGroupEnum::BLOG)->where('slug', $this->category)->first()
            : null;
    }

    #[Computed]
    public function activeTag(): ?Tag
    {
        return $this->tag ? Tag::query()->active()->where('slug', $this->tag)->first() : null;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return app(BlogService::class)->categoriesFor(CategoryGroupEnum::BLOG);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function filterCategory(?string $slug): void
    {
        $this->category = $slug;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'tag');
        $this->resetPage();
    }
};
?>

<div class="mx-auto max-w-6xl space-y-8 px-4 py-12">
    <div class="text-center">
        <flux:heading level="1" size="xl">
            {{ $this->activeCategory?->name ?? 'Blog' }}
        </flux:heading>
        <flux:text class="mx-auto mt-2 max-w-2xl">
            {{ $this->activeCategory?->description ?? 'News, notes and the occasional long read.' }}
        </flux:text>
    </div>

    <div class="flex flex-wrap items-center justify-center gap-2">
        <flux:button
            size="sm"
            variant="{{ $category === null ? 'primary' : 'ghost' }}"
            wire:click="filterCategory(null)"
        >
            All
        </flux:button>
        @foreach ($this->categories as $item)
            <flux:button
                wire:key="cat-{{ $item->id }}"
                size="sm"
                variant="{{ $category === $item->slug ? 'primary' : 'ghost' }}"
                wire:click="filterCategory('{{ $item->slug }}')"
            >
                {{ $item->name }}
            </flux:button>
        @endforeach
    </div>

    <div class="mx-auto max-w-md">
        <flux:input
            wire:model.live.debounce.400ms="search"
            placeholder="Search the blog"
            icon="magnifying-glass"
        />
    </div>

    @if ($this->activeTag)
        <div class="flex items-center justify-center gap-2">
            <flux:text size="sm">Tagged</flux:text>
            <flux:badge size="sm">{{ $this->activeTag->name }}</flux:badge>
            <flux:button size="xs" variant="ghost" wire:click="clearFilters">Clear</flux:button>
        </div>
    @endif

    @if ($this->posts->isEmpty())
        <x-dashboard.workspace-no-record
            icon="newspaper"
            label="Nothing to read yet"
            text="There are no posts matching this just now. Try another category."
        />
    @else
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->posts as $post)
                <article wire:key="post-{{ $post->id }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                    <a href="{{ route('blog.show', $post) }}" wire:navigate class="block aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
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

        <div>{{ $this->posts->links() }}</div>
    @endif
</div>
