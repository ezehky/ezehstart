<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Models\Tag;
use App\Services\ActivityLogService;
use App\Services\TagService;
use App\Traits\WithDataTable;
use App\Traits\WithFileImport;
use App\Traits\WithStatusToggle;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithFileImport, WithPagination, WithStatusToggle;

    public ?Tag $tag = null;

    public string $name = '';

    public bool $status = true;

    /**
     * The bulk box. Tags arrive in handfuls — off the back of a content plan, or
     * pasted from wherever the last site kept them — and one modal per name is a
     * chore nobody does.
     */
    public string $bulk_names = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        kSetSiteTitle('content', 'tags');
        $this->setPageGate('content.tags');
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'name' => ['label' => 'Name', 'locked' => true, 'sortable' => true],
            'status' => ['label' => 'Status', 'sortable' => true],
            'created_at' => ['label' => 'Added', 'sortable' => true],
        ];
    }

    protected function tableQuery(): Builder
    {
        $query = Tag::query()
            ->when($this->search, fn (Builder $query) => $query->searchMacro('name', $this->search));

        return $this->applyDateRange($query);
    }

    protected function tableSubject(): string
    {
        return 'tags';
    }

    /**
     * Tags are the one listing where clearing a handful at once is the normal job —
     * they arrive by being typed, and tidying up is most of what this screen is for.
     */
    protected function tableDeletable(): bool
    {
        return true;
    }

    protected function tableDeleteAction(): ActivityActionEnum
    {
        return ActivityActionEnum::TAG_DELETE;
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'created_at' => $item->createdAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->tags);
    }

    #[Computed]
    public function tags()
    {
        return $this->applySort($this->tableQuery(), 'name', 'asc')->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->tags;
    }

    public function updatedSearch(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function create(): void
    {
        $this->respondError(
            'You do not have access to add tags.',
            if: ! kGate('content.tags', GateAccessEnum::CREATE),
        );

        $this->resetForm();

        Flux::modal('tagModal')->show();
    }

    public function createMany(): void
    {
        $this->respondError(
            'You do not have access to add tags.',
            if: ! kGate('content.tags', GateAccessEnum::CREATE),
        );

        $this->reset('bulk_names');
        $this->resetValidation();

        Flux::modal('tagBulkModal')->show();
    }

    /**
     * Add a pasted list in one go.
     *
     * TagService already turns a comma-separated string into rows for the post
     * editor, matching on the slug and leaving anything that exists alone, which
     * is exactly what this screen wants — so it is the same call, not a second
     * implementation of it.
     */
    public function saveMany(): bool
    {
        $this->respondError(
            'You do not have access to add tags.',
            if: ! kGate('content.tags', GateAccessEnum::CREATE),
        );

        $this->validate(['bulk_names' => ['required', 'string', 'max:2000']]);

        $tags = app(TagService::class)->resolveTags($this->bulk_names);

        // firstOrCreate flags the rows it actually inserted, and that is the only
        // way to tell the admin how many of the pasted names were new.
        $created = $tags->filter(fn (Tag $item) => $item->wasRecentlyCreated);

        $this->respondPrimary('Every one of those already exists.', if: $created->isEmpty());

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::TAG_CREATE,
            ' tags: '.$created->pluck('name')->implode(', '),
        );

        Flux::modal('tagBulkModal')->close();
        $this->reset('bulk_names');
        unset($this->tags);

        return $this->respondSuccess(kPluralize('tag', $created->count()).' added.');
    }

    public function edit(Tag $tag): void
    {
        $this->respondError(
            'You do not have access to edit tags.',
            if: ! kGate('content.tags', GateAccessEnum::MODIFY),
        );

        $this->resetForm();

        $this->tag = $tag;
        $this->name = $tag->name;
        $this->status = $tag->status->isActive();

        Flux::modal('tagModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Tag::class, 'name')->ignore($this->tag?->id),
            ],
            'status' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        $this->respondError(
            'You do not have access to save tags.',
            if: ! kGate('content.tags', GateAccessEnum::MODIFY),
        );

        $this->validate();

        $action = ActivityActionEnum::TAG_UPDATE;

        if (! $this->tag) {
            $this->tag = Tag::make();
            $action = ActivityActionEnum::TAG_CREATE;
        }

        $this->tag->name = $this->name;
        $this->tag->status = StatusDefault::tryFrom((int) $this->status);

        $this->respondPrimary(if: $this->tag->isClean());

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->tag);

        if ($this->tag->isDirty('name')) {
            $this->tag->slug = kSlug($this->name);
        }

        $this->tag->save();

        $serviceInstance->logActivity(
            $action,
            " tag: {$this->tag->name}",
            $affectedColumns,
            model: $this->tag,
        );

        Flux::modal('tagModal')->close();
        $this->resetForm();
        unset($this->tags);

        return $this->respondSuccess('The tag has been saved.');
    }

    public function confirmDelete(Tag $tag): void
    {
        $this->respondError(
            'You do not have delete access to tags.',
            if: ! kGate('content.tags', GateAccessEnum::FULL),
        );

        $this->tag = $tag;

        Flux::modal('deleteTagModal')->show();
    }

    public function delete(): bool
    {
        $this->respondError(
            'You do not have delete access to tags.',
            if: ! kGate('content.tags', GateAccessEnum::FULL),
        );

        $this->respondError('Select a tag to delete first.', if: ! $this->tag);

        $description = " tag: {$this->tag->name}";

        $this->tag->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::TAG_DELETE, $description);

        Flux::modal('deleteTagModal')->close();
        $this->resetForm();
        unset($this->tags);

        return $this->respondSuccess('The tag has been deleted.');
    }

    /**
     * Open the import box. The state is cleared on the way in so a second run does
     * not open onto the last one's skipped lines.
     */
    public function startImport(): void
    {
        $this->checkGate(GateAccessEnum::CREATE, 'You do not have access to import tags.');

        $this->reset('importFile', 'importSkipped', 'importedCount');
        $this->resetValidation();

        Flux::modal('tagImportModal')->show();
    }

    /**
     * The column an imported file has to carry, for WithFileImport.
     */
    protected function importColumns(): array
    {
        return ['name'];
    }

    protected function importSubject(): string
    {
        return 'tags';
    }

    /**
     * One row of an imported file. A name already on the list is not a failure — an
     * import is worth having because it can be run twice — so it counts as a row that
     * did nothing rather than one that broke.
     */
    protected function importRow(array $row, int $line): bool
    {
        $name = trim((string) $row['name']);

        if ($name === '') {
            throw new \RuntimeException('The name is blank.');
        }

        return (bool) app(TagService::class)->resolveTags([$name])->first()?->wasRecentlyCreated;
    }

    protected function afterImport(): void
    {
        unset($this->tags);

        // Left open where rows were refused: the skipped list is the only place those
        // lines are named, and closing the box would take it away unread.
        if ($this->importSkipped === []) {
            Flux::modal('tagImportModal')->close();
        }
    }

    /**
     * The record behind a row switch, for WithStatusToggle.
     */
    protected function statusRecord(int|string $key): ?Model
    {
        return Tag::find($key);
    }

    protected function statusAction(): ActivityActionEnum
    {
        return ActivityActionEnum::TAG_UPDATE;
    }

    protected function afterStatusToggle(): void
    {
        unset($this->tags);
    }

    private function resetForm(): void
    {
        $this->reset('tag', 'name', 'status');
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Tags</flux:heading>
                <flux:text class="mt-1">
                    Free labels shared across everything. Authors create them by typing, so
                    this screen is mostly for tidying up.
                </flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search tags"
                    icon="magnifying-glass"
                />
                <x-dashboard.gate.button gate="content.tags" level="create" variant="filled" icon="arrow-up-tray" wire:click="startImport">Import</x-dashboard.gate.button>
                <x-dashboard.gate.button gate="content.tags" level="create" variant="filled" icon="queue-list" wire:click="createMany">Add many</x-dashboard.gate.button>
                <x-dashboard.gate.button gate="content.tags" level="create" variant="primary" icon="plus" wire:click="create">New tag</x-dashboard.gate.button>
            </div>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-form.date-field
                mode="range"
                wire:model.live="dateFrom"
                end-model="dateTo"
                with-presets
                label="Added between"
                class="sm:max-w-md"
            />

            <x-table.column-manager :columns="$this->tableColumnList" />
        </div>

        <x-table.bulk-bar
            :count="$this->selectedCount"
            :matching="$selectMatching"
            subject="tags"
            gate="content.tags"
            deletable
        />

        <flux:table :paginate="$this->tags">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                selectable
                actions
                actions-label=""
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->tags as $item)
                    <flux:table.row wire:key="tag-{{ $item->id }}">
                        <x-table.select :id="$item->id" />

                        <x-table.cell column="name" class="font-medium">{{ $item->name }}</x-table.cell>

                        <x-table.cell column="status">
                            <x-util.status-toggle :status="$item->status" :id="$item->id" gate="content.tags" />
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>

                        <x-table.cell class="flex justify-end gap-1">
                            <x-dashboard.gate.button gate="content.tags" level="modify" size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" />
                            <x-dashboard.gate.button gate="content.tags" level="full" size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $item->id }})" />
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        selectable
                        actions
                        label="No tags yet"
                        icon="hashtag"
                        text="They appear here as soon as an author uses one, or as soon as a file is imported."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="tagModal" class="modal-sm">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $tag ? 'Edit tag' : 'New tag' }}</flux:heading>

            <flux:input wire:model.blur="name" label="Name" placeholder="Tag" />
            <flux:switch wire:model="status" label="Active" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="tagBulkModal" class="modal-sm">
        <form wire:submit="saveMany" class="space-y-4">
            <flux:heading size="lg">Add many tags</flux:heading>

            <flux:textarea
                wire:model="bulk_names"
                label="Names"
                rows="4"
                placeholder="skincare, routine, winter"
                description="Separate them with commas. Names that already exist are left as they are."
            />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Add them</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="tagImportModal" class="modal-sm">
        <form wire:submit="import" class="space-y-4">
            <flux:heading size="lg">Import tags</flux:heading>

            <flux:text>
                One column headed <strong>name</strong>. A tag already on the list is left
                where it is, so the same file can be sent up twice without making duplicates.
            </flux:text>

            <x-form.file-field
                wire:model="importFile"
                label="Spreadsheet"
                formats="CSV or XLSX"
                maxSize="2 MB"
                accept=".csv,.txt,.xlsx"
            />

            {{-- Named lines rather than a count: an import that quietly dropped four
                 rows is worse than one that says which four. --}}
            @if ($importSkipped)
                <flux:callout icon="exclamation-triangle" variant="warning">
                    <flux:callout.text>
                        <span class="font-medium">{{ count($importSkipped) }} row{{ count($importSkipped) === 1 ? '' : 's' }} could not be used.</span>

                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach (array_slice($importSkipped, 0, 10) as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>

                        @if (count($importSkipped) > 10)
                            <p class="mt-2">…and {{ count($importSkipped) - 10 }} more.</p>
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="import, importFile">Import</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteTagModal"
        title="Delete this tag?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        It is removed from everywhere that carries it. The owners themselves are untouched.
    </x-dashboard.confirm-modal>
</div>
