<?php

use App\Enums\ActivityActionEnum;
use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Models\Category;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public ?Category $category = null;

    public CategoryGroupEnum $category_group;

    public string $name = '';

    public ?int $parent_id = null;

    public ?string $description = null;

    public int $flow_order = 0;

    public bool $status = true;

    public function mount(): void
    {
        kSetSiteTitle($this->category_group->parentTitle(), 'categories');
    }

    /**
     * @return Collection<string, Collection<int, Category>>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->withCount($this->category_group->morphName())
            ->inFlowOrder()
            ->where('category_group', $this->category_group)
            ->get()
            ->map(function ($item) {
                $morphName = $this->category_group->morphName();
                $item->attached_count = $item->getAttribute("{$morphName}_count");

                return $item;
            });
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
        $this->resetForm();
        $this->flow_order = (int) Category::query()
            ->where('category_group', $this->category_group)
            ->max('flow_order') + 1;

        Flux::modal('categoryModal')->show();
    }

    public function edit(Category $category): void
    {
        $this->resetForm();

        $this->category = $category;
        $this->fill($category->only(['name', 'parent_id', 'description', 'flow_order']));
        $this->status = $category->status->isActive();

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
        ];
    }

    public function save(): bool
    {
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
        $this->category->status = StatusDefault::from((int) $this->status);

        $this->respondPrimary(if: $this->category->isClean());

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->category);

        // If the name changed, we need to update the slug too. The slug is not
        if ($this->category->isDirty('name')) {
            $this->category->slug = kSlug("{$this->name} {$this->category_group->value}");
        }

        // Save
        $this->category->save();

        $serviceInstance->logActivity(
            $action,
            " category: {$this->category->name}",
            $affectedColumns,
            model: $this->category,
        );

        Flux::modal('categoryModal')->close();
        $this->resetForm();
        unset($this->grouped);

        return $this->respondSuccess('The category has been saved.');
    }

    public function confirmDelete(Category $category): void
    {
        // The category is held in a property so the modal can show its name while the
        $this->category = $category;

        Flux::modal('deleteCategoryModal')->show();
    }

    public function delete(): bool
    {
        $this->respondError('Select a category to delete first.', if: ! $this->category);

        // Nothing may be left filed under a category that is about to go — nor
        // under one of its children, which survive the delete with their
        // parent_id nulled and would carry their posts up to the top level.
        $morphName = $this->category_group->morphName();

        $inUse = Category::query()
            ->whereHas($morphName)
            ->where(fn ($query) => $query->whereKey($this->category->id)->orWhere('parent_id', $this->category->id))
            ->exists();

        $this->respondError(
            "This category, or one of its sub-categories, still has {$morphName} attached. Move them first.",
            if: $inUse,
        );

        $description = " category: {$this->category->name}";

        // The pivot rows go by cascade and the children are promoted by the
        // nulling foreign key, so there is nothing to unpick here by hand.
        $this->category->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::CATEGORY_DELETE, $description);

        Flux::modal('deleteCategoryModal')->close();
        $this->resetForm();
        unset($this->grouped);

        return $this->respondSuccess('The category has been deleted.');
    }

    private function resetForm(): void
    {
        $this->reset('category', 'name', 'parent_id', 'description', 'flow_order', 'status');
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
            <flux:button size="sm" icon="plus" wire:click="create">Add</flux:button>
        </div>

        <flux:table :pagination="$this->categories" class="space-y-4">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Parent</flux:table.column>
                <flux:table.column>Attached</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->categories as $item)
                    <flux:table.row wire:key="category-{{ $item->id }}">
                        <flux:table.cell>{{ $item->name }}</flux:table.cell>
                        <flux:table.cell>{{ $item->parentName() }}</flux:table.cell>
                        <flux:table.cell>{{ $item->attached_count }}</flux:table.cell>
                        <flux:table.cell><x-status :status="$item->status" /></flux:table.cell>
                        <flux:table.cell class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" />
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $item->id }})" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <x-dashboard.workspace-no-record
                                icon="tag"
                                label="Nothing here"
                                text="Add the first {{ $this->category_group->label(true) }} category."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="categoryModal" class="modal-sm">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $category ? 'Edit category' : 'New category' }}</flux:heading>

            <flux:input wire:model="name" label="Name" placeholder="Category" />

            <flux:select wire:model="parent_id" label="Parent">
                <flux:select.option value="">Top level</flux:select.option>
                @foreach ($this->parentOptions as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="description" label="Description" rows="2" placeholder="Type..." />
            <x-form.number-field wire:model="flow_order" label="Order" />

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
