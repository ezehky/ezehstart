<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusPost;
use App\Models\Post;
use App\Services\ActivityLogService;
use App\Services\BlogService;
use App\Services\ImageLibraryService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination;

    public ?Post $post = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        kSetSiteTitle('content', 'blogs');
        $this->setPageGate('content.blogs');
    }

    /**
     * Is this account held to the posts it wrote? Drives the copy above the table,
     * so a short list reads as a rule rather than as missing data.
     */
    #[Computed]
    public function ownPostsOnly(): bool
    {
        return app(BlogService::class)->authorRestricted(auth()->user());
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'title' => $this->columnMaker('Post', locked: true, sortable: true),
            'categories' => $this->columnMaker('Categories'),
            'user' => $this->columnMaker('Author'),
            'views' => $this->columnMaker('Reads', sortable: true, summary: 'sum'),
            'status' => $this->columnMaker('Status', sortable: true),
            'published_at' => $this->columnMaker('Published', sortable: true),
        ];
    }

    /**
     * Author-scoped, like everything else on this screen: an export that quietly
     * handed an author the whole archive would be a way around the rule the listing
     * is enforcing.
     */
    protected function tableQuery(): Builder
    {
        $query = app(BlogService::class)
            ->authorScope(Post::query(), auth()->user())
            ->with(['user', 'image', 'categories'])
            ->when($this->search, fn (Builder $inner) => $inner->searchMacro('title', $this->search))
            ->when($this->status !== '', fn (Builder $inner) => $inner->where('status', (int) $this->status));

        return $this->applyDateRange($query, 'published_at');
    }

    protected function tableSubject(): string
    {
        return 'posts';
    }

    /**
     * The filters, for the chips that take them off again.
     */
    protected function tableFilters(): array
    {
        return [
            'search' => $this->filterMaker('Search'),
            'status' => $this->filterMaker('Status', StatusPost::forSelect()),
        ];
    }

    protected function tableDateLabel(): string
    {
        return 'Published';
    }

    protected function tableDateColumn(): string
    {
        return 'published_at';
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'categories' => $item->categories->pluck('name')->implode(', '),
            'user' => $item->authorName(),
            'published_at' => $item->published_at?->format('Y-m-d') ?? '',
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->posts, $this->metrics);
    }

    protected function tablePerPage(): int
    {
        return 15;
    }

    public function updatedSearch(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    #[Computed]
    public function posts()
    {
        return $this->applySort($this->tableQuery(), 'id')->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->posts;
    }

    /**
     * @return array<int, array{label: string, value: int, icon: string, tone: string}>
     */
    #[Computed]
    public function metrics(): array
    {
        // Scoped the same way the table is. A "Published: 40" above a list of the
        // four posts this author wrote is a number about somebody else's work.
        $service = app(BlogService::class);
        $scoped = fn () => $service->authorScope(Post::query(), auth()->user());

        return [
            $this->metricMaker('Published', $scoped()->live()->count(), 'megaphone', tone: 'emerald'),
            $this->metricMaker('Drafts', $scoped()->where('status', StatusPost::DRAFT)->count(), 'pencil-square', tone: 'amber'),
            $this->metricMaker('Total reads', (int) $scoped()->sum('views'), 'eye', tone: 'sky'),
        ];
    }

    public function confirmDelete(Post $post): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to posts.');

        $reason = app(BlogService::class)->editBlockedReason($post, auth()->user());
        $this->respondError($reason ?? '', $reason !== null);

        $this->post = $post;

        Flux::modal('deletePostModal')->show();
    }

    public function delete(): bool
    {
        // The menu row is hidden, which stops nobody who can open a console.
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to posts.');

        $this->respondError('Select a post to delete first.', ! $this->post);

        // Re-checked rather than trusted: the dialog was opened a while ago, and
        // ownership is the whole reason this post was reachable at all.
        $reason = app(BlogService::class)->editBlockedReason($this->post, auth()->user());
        $this->respondError($reason ?? '', $reason !== null);

        // Captured before the row goes, so the log line still says what went.
        $description = " post: {$this->post->title}";

        // Release the cover image first, or the usage row's restrict rule refuses
        // the delete and the administrator gets a foreign key error.
        app(ImageLibraryService::class)->detach($this->post, 'cover');

        $this->post->categories()->detach();
        $this->post->tags()->detach();
        $this->post->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::POST_DELETE, $description);

        Flux::modal('deletePostModal')->close();
        $this->reset('post');
        unset($this->posts, $this->metrics);

        return $this->respondSuccess('The post has been deleted.');
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Blog metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card :metric="$metric" />
        @endforeach
    </section>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Posts</flux:heading>
                <flux:text class="mt-1">
                    {{ $this->ownPostsOnly
                        ? 'The posts you have written, published or not.'
                        : 'Everything written for the blog, published or not.' }}
                </flux:text>
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
                <x-dashboard.gate.button
                    gate="content.blogs"
                    level="create"
                    href="{{ route('admin.blog.create') }}"
                    variant="primary"
                    icon="plus"
                >
                    New post
                </x-dashboard.gate.button>
            </div>
        </div>

        @if ($this->posts->isEmpty())
            <x-dashboard.workspace-no-record
                icon="newspaper"
                label="No posts yet"
                text="Write the first one and it will show up here."
            />
        @else
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <x-form.date-field
                    mode="range"
                    wire:model.live="dateFrom"
                    end-model="dateTo"
                    with-presets
                    label="Published between"
                    class="sm:max-w-md"
                />

                <x-table.column-manager :columns="$this->tableColumnList" />
            </div>

            <x-table.active-filters :filters="$this->tableActiveFilters" />

            <x-table.bulk-bar class="mb-5" :count="$this->selectedCount" :total="$this->tableTotalCount" :matching="$selectMatching" :columns="$this->tableExportOptions" subject="posts" />

            <flux:table :paginate="$this->posts">
                <x-table.columns
                    :columns="$this->tableColumnList"
                    :sort="$sortColumn"
                    :direction="$sortDirection"
                    selectable
                    actions
                    actions-label=""
                />

                <x-table.rows :columns="$this->tableColumnList">
                    @forelse ($this->posts as $item)
                        <flux:table.row wire:key="post-{{ $item->id }}">
                            <x-table.select :id="$item->id" />

                            <x-table.cell column="title">
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
                            </x-table.cell>

                            <x-table.cell column="categories">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($item->categories as $category)
                                        <flux:badge size="sm" inset="top bottom">{{ $category->name }}</flux:badge>
                                    @empty
                                        <flux:text size="sm">—</flux:text>
                                    @endforelse
                                </div>
                            </x-table.cell>

                            <x-table.cell column="user">{{ $item->authorName() }}</x-table.cell>
                            <x-table.cell column="views">{{ number_format($item->views) }}</x-table.cell>

                            <x-table.cell column="status">
                                <x-util.e-badge :enum="$item->status" />
                            </x-table.cell>

                            <x-table.cell column="published_at">
                                {{ $item->published_at ? $item->publishedAtHuman() : '—' }}
                            </x-table.cell>

                            <x-table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <x-dashboard.gate.menu-item
                                            gate="content.blogs"
                                            level="modify"
                                            icon="pencil-square"
                                            href="{{ route('admin.blog.edit', $item) }}"
                                        >
                                            Edit
                                        </x-dashboard.gate.menu-item>
                                        @if ($item->isLive())
                                            <flux:menu.item icon="arrow-top-right-on-square" href="{{ route('blog.show', $item) }}">
                                                View on site
                                            </flux:menu.item>
                                        @endif
                                        @if (kGateAction($pageGate, $gateFull))
                                            <flux:menu.separator />
                                        @endif
                                        <x-dashboard.gate.menu-item
                                            gate="content.blogs"
                                            level="full"
                                            icon="trash"
                                            variant="danger"
                                            wire:click="confirmDelete({{ $item->id }})"
                                        >
                                            Delete
                                        </x-dashboard.gate.menu-item>
                                    </flux:menu>
                                </flux:dropdown>
                            </x-table.cell>
                        </flux:table.row>
                    @empty
                        <x-table.empty
                            :columns="$this->tableColumnList"
                            selectable
                            actions
                            label="No posts"
                            icon="newspaper"
                            text="No posts match the current filters."
                        />
                    @endforelse

                    <x-table.summary
                        :columns="$this->tableColumnList"
                        :summary="$this->tableSummary"
                        selectable
                        actions
                    />
                </x-table.rows>
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
