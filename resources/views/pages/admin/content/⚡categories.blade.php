<?php

use App\Enums\ActivityActionEnum;
use App\Enums\CategoryGroupEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Models\Category;
use App\Services\ActivityLogService;
use App\Services\CategoryService;
use App\Traits\WithDataTable;
use App\Traits\WithFileImport;
use App\Traits\WithImagePicker;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use WithDataTable, WithFileImport, WithImagePicker;

    public ?Category $category = null;

    public CategoryGroupEnum $category_group;

    public string $name = '';

    public ?int $parent_id = null;

    public ?string $description = null;

    public int $flow_order = 0;

    /** The cover, kept in step with the 'cover' slot by WithImagePicker. */
    public ?int $image_id = null;

    public bool $status = true;

    /**
     * The bulk box. Setting up a group means typing out its whole vocabulary at
     * once, and one modal per name is a chore nobody does.
     */
    public string $bulk_names = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        kSetSiteTitle($this->category_group->parentTitle(), 'categories');
        $this->setPageGate('content.categories');
    }

    /**
     * One cover image per category, on the column the table already carries.
     *
     * A category is a thing people see — a chip on a card, a tile on an index — far
     * more often than a row in this list, and it reads as a label with nothing behind
     * it until it has a picture.
     */
    protected function imageSlots(): array
    {
        return [
            'cover' => ['multiple' => false, 'property' => 'image_id'],
        ];
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'image' => ['label' => 'Image', 'exportable' => false],
            'name' => ['label' => 'Name', 'locked' => true, 'sortable' => true],
            'parent' => ['label' => 'Parent'],
            'attached_count' => ['label' => 'Attached', 'exportable' => false],
            'flow_order' => ['label' => 'Order', 'sortable' => true],
            'status' => ['label' => 'Status', 'sortable' => true],
            'created_at' => ['label' => 'Added', 'sortable' => true],
        ];
    }

    protected function tableQuery(): Builder
    {
        $query = Category::query()
            ->with('image')
            ->withCount($this->category_group->morphName())
            ->where('category_group', $this->category_group)
            ->when($this->search !== '', fn (Builder $inner) => $inner->searchMacro('name', $this->search));

        return $this->applyDateRange($query);
    }

    protected function tableSubject(): string
    {
        return 'categories';
    }

    /**
     * The filters, for the chips that take them off again. The group is not one of
     * them — it is which screen this is rather than a narrowing of it.
     */
    protected function tableFilters(): array
    {
        return ['search' => ['label' => 'Search']];
    }

    protected function tableDateLabel(): string
    {
        return 'Added';
    }

    protected function tableDeletable(): bool
    {
        return true;
    }

    protected function tableDeleteAction(): ActivityActionEnum
    {
        return ActivityActionEnum::CATEGORY_DELETE;
    }

    /**
     * The same guard the single-row delete does, so a selection of twenty is not the
     * way round it.
     */
    protected function tableDeleteBlocked(Model $item): ?string
    {
        return $this->attachmentBlockReason($item);
    }

    /**
     * The whole listing is on one page, so the header checkbox takes all of it.
     *
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->categories;
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'parent' => $item->parentName(),
            'created_at' => $item->createdAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->categories, $this->parentOptions);
    }

    /**
     * The column an imported file has to carry. Parent, description and order are
     * taken when they are there and left alone when they are not.
     */
    protected function importColumns(): array
    {
        return ['name'];
    }

    protected function importSubject(): string
    {
        return 'categories';
    }

    /**
     * One row of an imported file. A name already in this group is left where it is —
     * an import is worth having because it can be run twice.
     */
    protected function importRow(array $row, int $line): bool
    {
        $name = trim((string) $row['name']);

        if ($name === '') {
            throw new \RuntimeException('The name is blank.');
        }

        $category = Category::query()->firstOrNew([
            'category_group' => $this->category_group,
            'slug' => kSlug("{$name} {$this->category_group->value}"),
        ]);

        if ($category->exists) {
            return false;
        }

        $category->name = $name;
        $category->description = trim((string) ($row['description'] ?? '')) ?: null;
        $category->flow_order = (int) ($row['order'] ?? $row['flow_order'] ?? 0);
        $category->status = StatusDefault::ACTIVE;

        // A parent named in the file is matched inside this group, and a name that
        // matches nothing is left at the top level rather than failing the row.
        if ($parent = trim((string) ($row['parent'] ?? ''))) {
            $category->parent_id = Category::query()
                ->inGroup($this->category_group)
                ->where('name', $parent)
                ->value('id');
        }

        $category->save();

        return true;
    }

    protected function afterImport(): void
    {
        unset($this->categories, $this->parentOptions);

        if ($this->importSkipped === []) {
            Flux::modal('categoryImportModal')->close();
        }
    }

    public function startImport(): void
    {
        $this->checkGate(GateAccessEnum::CREATE, 'You do not have access to import categories.');

        $this->reset('importFile', 'importSkipped', 'importedCount');
        $this->resetValidation();

        Flux::modal('categoryImportModal')->show();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->applySort($this->tableQuery(), 'flow_order', 'asc')
            ->get()
            ->map(function ($item) {
                $morphName = $this->category_group->morphName();
                $item->attached_count = $item->getAttribute("{$morphName}_count");

                return $item;
            });
    }

    public function updatedSearch(): void
    {
        $this->clearSelection();
    }

    /**
     * Candidate parents: same group, and never the row being edited — a category
     * that is its own parent makes the breadcrumb walk forever.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        return Category::query()
            ->inGroup($this->category_group)
            ->when($this->category, fn ($query) => $query->whereKeyNot($this->category->id))
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->respondError(
            'You do not have access to add categories.',
            if: ! kGate('content.categories', GateAccessEnum::CREATE),
        );

        $this->resetForm();
        $this->flow_order = (int) Category::query()
            ->where('category_group', $this->category_group)
            ->max('flow_order') + 1;

        Flux::modal('categoryModal')->show();
    }

    public function createMany(): void
    {
        $this->respondError(
            'You do not have access to add categories.',
            if: ! kGate('content.categories', GateAccessEnum::CREATE),
        );

        $this->reset('bulk_names');
        $this->resetValidation();

        Flux::modal('categoryBulkModal')->show();
    }

    /**
     * Add a pasted list in one go.
     *
     * Everything a bulk row does not carry — parent, description — is what the
     * edit modal is for afterwards. The names and their order are the part worth
     * typing once.
     */
    public function saveMany(): bool
    {
        $this->respondError(
            'You do not have access to add categories.',
            if: ! kGate('content.categories', GateAccessEnum::CREATE),
        );

        $this->validate(['bulk_names' => ['required', 'string', 'max:2000']]);

        $categories = app(CategoryService::class)->resolveCategories($this->bulk_names, $this->category_group);

        // firstOrCreate flags the rows it actually inserted, and that is the only
        // way to tell the admin how many of the pasted names were new.
        $created = $categories->filter(fn (Category $item) => $item->wasRecentlyCreated);

        $this->respondPrimary('Every one of those is already in this group.', if: $created->isEmpty());

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::CATEGORY_CREATE,
            ' categories: '.$created->pluck('name')->implode(', '),
        );

        Flux::modal('categoryBulkModal')->close();
        $this->reset('bulk_names');
        unset($this->categories, $this->parentOptions);

        return $this->respondSuccess(kPluralize('category', $created->count()).' added.');
    }

    public function edit(Category $category): void
    {
        $this->respondError(
            'You do not have access to edit categories.',
            if: ! kGate('content.categories', GateAccessEnum::MODIFY),
        );

        $this->resetForm();

        $this->category = $category;
        $this->fill($category->only(['name', 'parent_id', 'description', 'flow_order', 'image_id']));
        $this->status = $category->status->isActive();

        $this->loadImageSlots($category);

        Flux::modal('categoryModal')->show();
    }

    protected function rules(): array
    {
        return [
            'category_group' => ['required', Rule::enum(CategoryGroupEnum::class)],
            'name' => [
                'required',
                'string',
                'max:255',
                // Unique per group, not globally — "skincare" may legitimately be
                // both a blog category and a product one.
                Rule::unique(Category::class, 'name')
                    ->where('category_group', $this->category_group)
                    ->ignore($this->category?->id),
            ],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:1000'],
            'flow_order' => ['required', 'integer', 'min:0'],
            'status' => ['boolean'],
            ...$this->imagePickerRules(),
        ];
    }

    public function save(): bool
    {
        $this->respondError(
            'You do not have access to save categories.',
            if: ! kGate('content.categories', GateAccessEnum::MODIFY),
        );

        $this->validate();

        $action = ActivityActionEnum::CATEGORY_UPDATE;

        if (! $this->category) {
            $this->category = Category::make();
            $action = ActivityActionEnum::CATEGORY_CREATE;
        }

        $this->category->category_group = $this->category_group;
        $this->category->name = $this->name;
        $this->category->parent_id = $this->parent_id;
        $this->category->description = $this->description;
        $this->category->flow_order = $this->flow_order;
        $this->category->image_id = $this->image_id;
        $this->category->status = StatusDefault::tryFrom((int) $this->status);

        $this->respondPrimary(if: $this->category->isClean());

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->category);

        // If the name changed, we need to update the slug too. The slug is not
        if ($this->category->isDirty('name')) {
            $this->category->slug = kSlug("{$this->name} {$this->category_group->value}");
        }

        // Save
        $this->category->save();

        // The usage row is what stops somebody deleting an image a category is
        // wearing. After the save, because a new category has no id before it.
        $this->syncImageSlots($this->category);

        $serviceInstance->logActivity(
            $action,
            " category: {$this->category->name}",
            $affectedColumns,
            model: $this->category,
        );

        Flux::modal('categoryModal')->close();
        $this->resetForm();
        unset($this->categories, $this->parentOptions);

        return $this->respondSuccess('The category has been saved.');
    }

    public function confirmDelete(Category $category): void
    {
        $this->respondError(
            'You do not have delete access to categories.',
            if: ! kGate('content.categories', GateAccessEnum::FULL),
        );

        // The category is held in a property so the modal can show its name while the
        $this->category = $category;

        Flux::modal('deleteCategoryModal')->show();
    }

    public function delete(): bool
    {
        $this->respondError(
            'You do not have delete access to categories.',
            if: ! kGate('content.categories', GateAccessEnum::FULL),
        );

        $this->respondError('Select a category to delete first.', if: ! $this->category);

        $blocked = $this->attachmentBlockReason($this->category);

        $this->respondError($blocked.' Move them first.', if: $blocked !== null);

        $description = " category: {$this->category->name}";

        // The pivot rows go by cascade and the children are promoted by the
        // nulling foreign key, so there is nothing to unpick here by hand.
        $this->category->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::CATEGORY_DELETE, $description);

        Flux::modal('deleteCategoryModal')->close();
        $this->resetForm();
        unset($this->categories, $this->parentOptions);

        return $this->respondSuccess('The category has been deleted.');
    }

    /**
     * Why a category cannot go, or null if it can.
     *
     * Nothing may be left filed under a category that is about to be deleted — nor
     * under one of its children, which survive the delete with their parent_id nulled
     * and would carry their posts up to the top level.
     */
    private function attachmentBlockReason(Model $item): ?string
    {
        $morphName = $this->category_group->morphName();

        $inUse = Category::query()
            ->whereHas($morphName)
            ->where(fn ($query) => $query->whereKey($item->getKey())->orWhere('parent_id', $item->getKey()))
            ->exists();

        return $inUse
            ? "\"{$item->name}\" or one of its sub-categories still has {$morphName} attached."
            : null;
    }

    private function resetForm(): void
    {
        $this->reset('category', 'name', 'parent_id', 'description', 'flow_order', 'status', 'image_id', 'image_slots');
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <flux:card>
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading level="1" size="xl">Categories: {{ $this->category_group->label() }}</flux:heading>
                <flux:text class="mt-1">
                    Manage your {{ $this->category_group->label(true) }} categories.
                </flux:text>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-56"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search categories"
                    icon="magnifying-glass"
                />
                <x-dashboard.gate.button gate="content.categories" level="create" size="sm" variant="filled" icon="arrow-up-tray" wire:click="startImport">Import</x-dashboard.gate.button>
                <x-dashboard.gate.button gate="content.categories" level="create" size="sm" variant="filled" icon="queue-list" wire:click="createMany">Add many</x-dashboard.gate.button>
                <x-dashboard.gate.button gate="content.categories" level="create" size="sm" icon="plus" wire:click="create">Add</x-dashboard.gate.button>
            </div>
        </div>

        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
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

        <x-table.active-filters :filters="$this->tableActiveFilters" />

        <x-table.bulk-bar
            class="mt-5"
            :count="$this->selectedCount"
            :total="$this->tableTotalCount"
            :matching="$selectMatching"
            :columns="$this->tableExportOptions"
            subject="categories"
            gate="content.categories"
            deletable
        />

        <flux:table class="mt-5">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                selectable
                actions
                actions-label=""
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->categories as $item)
                    <flux:table.row wire:key="category-{{ $item->id }}">
                        <x-table.select :id="$item->id" />

                        <x-table.cell column="image">
                            @if ($item->image)
                                <img src="{{ $item->image->url() }}" alt="{{ $item->name }}" class="size-9 rounded-lg object-cover" />
                            @else
                                <span class="grid size-9 place-items-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                                    <flux:icon name="photo" class="size-4" />
                                </span>
                            @endif
                        </x-table.cell>

                        <x-table.cell column="name" class="font-medium">{{ $item->name }}</x-table.cell>
                        <x-table.cell column="parent">{{ $item->parentName() }}</x-table.cell>
                        <x-table.cell column="attached_count">{{ number_format($item->attached_count) }}</x-table.cell>
                        <x-table.cell column="flow_order">{{ $item->flow_order }}</x-table.cell>

                        <x-table.cell column="status">
                            <x-util.status-toggle :status="$item->status" :id="$item->id" gate="content.categories" />
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>

                        <x-table.cell class="flex justify-end gap-1">
                            <x-dashboard.gate.button gate="content.categories" level="modify" size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" />
                            <x-dashboard.gate.button gate="content.categories" level="full" size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $item->id }})" />
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        selectable
                        actions
                        icon="tag"
                        label="Nothing here"
                        text="Add the first {{ $this->category_group->label(true) }} category, or import a list of them."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="categoryModal" class="modal-sm">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $category ? 'Edit category' : 'New category' }}</flux:heading>

            <flux:input wire:model="name" label="Name" placeholder="Category" />

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="parent_id" label="Parent">
                    <flux:select.option value="">Top level</flux:select.option>
                    @foreach ($this->parentOptions as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <x-form.number-field wire:model="flow_order" label="Order" />
            </div>

            <flux:textarea wire:model="description" label="Description" rows="2" placeholder="Type..." />

            <x-form.image-slot
                name="cover"
                label="Image"
                :images="$this->slotImages('cover')"
                error="image_id"
            />

            <div class="flex justify-end gap-3">
                <div class="flex items-center">
                    <flux:switch wire:model="status" label="Active" />
                </div>

                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="categoryBulkModal" class="modal-sm">
        <form wire:submit="saveMany" class="space-y-4">
            <flux:heading size="lg">Add many {{ $category_group->label(true) }} categories</flux:heading>

            <flux:textarea
                wire:model="bulk_names"
                label="Names"
                rows="4"
                placeholder="Skincare, Routine, Winter"
                description="Separate them with commas. They are added in the order typed, and names already in this group are left as they are."
            />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Add them</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="categoryImportModal" class="modal-sm">
        <form wire:submit="import" class="space-y-4">
            <flux:heading size="lg">Import {{ $category_group->label(true) }} categories</flux:heading>

            <flux:text>
                A column headed <strong>name</strong>, and optionally <strong>parent</strong>,
                <strong>description</strong> and <strong>order</strong>. A name already in this
                group is left where it is, so the same file can be sent up twice.
            </flux:text>

            <x-form.file-field
                wire:model="importFile"
                label="Spreadsheet"
                formats="CSV or XLSX"
                maxSize="2 MB"
                accept=".csv,.txt,.xlsx"
            />

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

    <livewire:livewire.library.image-picker />

    <x-dashboard.confirm-modal
        name="deleteCategoryModal"
        title="Delete this category?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        A category with {{ $category_group->label(true) }} attached to it or to one of its sub-categories cannot be
        deleted. Otherwise, any sub-categories move up to the top level.
    </x-dashboard.confirm-modal>
</div>
