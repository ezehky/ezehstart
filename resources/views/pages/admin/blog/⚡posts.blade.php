<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusPost;
use App\Models\Post;
use App\Services\ActivityLogService;
use App\Services\ImageLibraryService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithFormResponseMessage, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    /** The post queued for deletion, held while the dialog asks. */
    public ?int $deleteId = null;

    public function mount(): void
    {
        kSetSiteTitle('blog', 'posts');
    }

    #[Computed]
    public function posts()
    {
        return Post::query()
            ->with(['user', 'image', 'categories'])
            ->when($this->search, fn (Builder $query) => $query->where('title', 'like', '%'.$this->search.'%'))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', (int) $this->status))
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * @return array<int, array{label: string, value: int, icon: string, tone: string}>
     */
    #[Computed]
    public function metrics(): array
    {
        return [
            [
                'label' => 'Published',
                'value' => Post::query()->live()->count(),
                'icon' => 'megaphone',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Drafts',
                'value' => Post::query()->where('status', StatusPost::DRAFT)->count(),
                'icon' => 'pencil-square',
                'tone' => 'amber',
            ],
            [
                'label' => 'Total reads',
                'value' => (int) Post::query()->sum('views'),
                'icon' => 'eye',
                'tone' => 'sky',
            ],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $postId): void
    {
        $this->deleteId = $postId;

        Flux::modal('deletePostModal')->show();
    }

    public function delete(): bool
    {
        $post = Post::query()->whereKey($this->deleteId)->first();

        abort_unless((bool) $post, 404);

        // Captured before the row goes, so the log line still says what went.
        $description = " post: {$post->title}";

        // Release the cover image first, or the usage row's restrict rule refuses
        // the delete and the administrator gets a foreign key error.
        app(ImageLibraryService::class)->detach($post, 'cover');

        $post->categories()->detach();
        $post->tags()->detach();
        $post->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::POST_DELETE, $description);

        Flux::modal('deletePostModal')->close();
        $this->reset('deleteId');
        unset($this->posts, $this->metrics);

        return $this->respondSuccess('The post has been deleted.');
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Blog metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card
                :label="$metric['label']"
                :value="$metric['value']"
                :icon="$metric['icon']"
                :tone="$metric['tone']"
            />
        @endforeach
    </section>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Posts</flux:heading>
                <flux:text class="mt-1">Everything written for the blog, published or not.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search titles"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="status" class="sm:min-w-40">
                    <flux:select.option value="">All statuses</flux:select.option>
                    @foreach (StatusPost::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button href="{{ route('admin.blog.post-create') }}" variant="primary" icon="plus">
                    New post
                </flux:button>
            </div>
        </div>

        @if ($this->posts->isEmpty())
            <x-dashboard.workspace-no-record
                icon="newspaper"
                label="No posts yet"
                text="Write the first one and it will show up here."
            />
        @else
            <flux:table :paginate="$this->posts">
                <flux:table.columns>
                    <flux:table.column>Post</flux:table.column>
                    <flux:table.column>Categories</flux:table.column>
                    <flux:table.column>Reads</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->posts as $item)
                        <flux:table.row wire:key="post-{{ $item->id }}">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    @if ($item->image)
                                        <img src="{{ $item->image->url() }}" alt="" class="size-10 rounded object-cover" />
                                    @else
                                        <x-dashboard.icon-box size="sm" icon="newspaper" tone="slate" />
                                    @endif
                                    <div>
                                        <p class="font-medium text-slate-950 dark:text-white">{{ $item->title }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $item->authorName() }} · {{ $item->createdHuman() }}
                                        </p>
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($item->categories as $category)
                                        <flux:badge size="sm" inset="top bottom">{{ $category->name }}</flux:badge>
                                    @empty
                                        <flux:text size="sm">—</flux:text>
                                    @endforelse
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($item->views) }}</flux:table.cell>
                            <flux:table.cell>
                                <x-status :status="$item->status" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item
                                            icon="pencil-square"
                                            href="{{ route('admin.blog.post-edit', $item) }}"
                                        >
                                            Edit
                                        </flux:menu.item>
                                        @if ($item->isLive())
                                            <flux:menu.item icon="arrow-top-right-on-square" href="{{ route('blog.show', $item) }}">
                                                View on site
                                            </flux:menu.item>
                                        @endif
                                        <flux:menu.separator />
                                        <flux:menu.item
                                            icon="trash"
                                            variant="danger"
                                            wire:click="confirmDelete({{ $item->id }})"
                                        >
                                            Delete
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <x-dashboard.confirm-modal
        name="deletePostModal"
        title="Delete this post?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        The post and its links to categories and tags are removed. Its cover image stays in
        the library and becomes free to delete again.
    </x-dashboard.confirm-modal>
</div>
