<?php

use App\Enums\ActivityActionEnum;
use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Models\Category;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public ?Category $category = null;

    public CategoryGroupEnum $category_group = CategoryGroupEnum::BLOG;

    public string $name = '';

    public string $slug = '';

    public ?int $parent_id = null;

    public ?string $description = null;

    public int $flow_order = 0;

    public bool $status = true;

    /** The category queued for deletion, held while the dialog asks. */
    public ?int $deleteId = null;

    public function mount(): void
    {
        kSetSiteTitle('content', 'blog-categories');
    }

    /**
     * Grouped by what they categorise, because a blog category and a product
     * category are not the same list even when they share a name.
     *
     * @return Collection<string, Collection<int, Category>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return Category::query()
            ->withCount('posts')
            ->inFlowOrder()
            ->get()
            ->groupBy(fn (Category $category) => $category->category_group->value);
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

    public function create(CategoryGroupEnum $group): void
    {
        $this->resetForm();

        $this->category_group = $group;
        $this->flow_order = (int) Category::query()->where('category_group', $group)->max('flow_order') + 1;

        Flux::modal('categoryModal')->show();
    }

    public function edit(Category $category): void
    {
        $this->resetForm();

        $this->category = $category;
        $this->fill($category->only(['name', 'slug', 'category_group', 'parent_id', 'description', 'flow_order']));
        $this->status = $category->status->isActive();

        Flux::modal('categoryModal')->show();
    }

    public function updatedName(string $value): void
    {
        if (! $this->category) {
            $this->slug = kSlug($value);
        }
    }

    protected function rules(): array
    {
        return [
            'category_group' => ['required', Rule::enum(CategoryGroupEnum::class)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                // Unique per group, not globally — "skincare" may legitimately be
                // both a blog category and a product one.
                Rule::unique(Category::class, 'slug')
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
        $this->category->slug = kSlug($this->slug);
        $this->category->parent_id = $this->parent_id;
        $this->category->description = $this->description;
        $this->category->flow_order = $this->flow_order;
        $this->category->status = StatusDefault::from((int) $this->status);

        $this->respondPrimary(if: $this->category->isClean());

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->category);

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

    public function confirmDelete(int $categoryId): void
    {
        $this->deleteId = $categoryId;

        Flux::modal('deleteCategoryModal')->show();
    }

    public function delete(): bool
    {
        $category = Category::query()->whereKey($this->deleteId)->first();

        abort_unless((bool) $category, 404);

        $description = " category: {$category->name}";

        // The pivot rows go by cascade and the children are promoted by the
        // nulling foreign key, so there is nothing to unpick here by hand.
        $category->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::CATEGORY_DELETE, $description);

        Flux::modal('deleteCategoryModal')->close();
        $this->reset('deleteId');
        unset($this->grouped);

        return $this->respondSuccess('The category has been deleted.');
    }

    private function resetForm(): void
    {
        $this->reset('category', 'name', 'category_group', 'slug', 'parent_id', 'description', 'flow_order', 'status');
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:heading level="1" size="xl">Categories</flux:heading>
        <flux:text class="mt-1">
            One table serves every kind of category. The group is what keeps a blog category
            out of a product picker.
        </flux:text>
    </div>

    @foreach (CategoryGroupEnum::cases() as $group)
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading level="2" size="lg">{{ $group->label() }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="create('{{ $group->value }}')">Add</flux:button>
            </div>

            @php($rows = $this->grouped->get($group->value))

            @if (! $rows || $rows->isEmpty())
                <x-dashboard.workspace-no-record
                    icon="tag"
                    label="Nothing here"
                    text="Add the first {{ mb_strtolower($group->label()) }} category."
                />
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Parent</flux:table.column>
                        <flux:table.column>Posts</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column />
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($rows as $item)
                            <flux:table.row wire:key="category-{{ $item->id }}">
                                <flux:table.cell>
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $item->name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">/{{ $item->slug }}</p>
                                </flux:table.cell>
                                <flux:table.cell>{{ $item->parent?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $item->posts_count }}</flux:table.cell>
                                <flux:table.cell><x-status :status="$item->status" /></flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $item->id }})" />
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endforeach

    <flux:modal name="categoryModal" class="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $category ? 'Edit category' : 'New category' }}</flux:heading>

            <flux:input wire:model.blur="name" label="Name" />
            <flux:input wire:model="slug" label="Slug" description="Unique within its group." />

            <flux:select wire:model="parent_id" label="Parent">
                <flux:select.option value="">Top level</flux:select.option>
                @foreach ($this->parentOptions as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="description" label="Description" rows="2" />
            <flux:input type="number" wire:model="flow_order" label="Order" />
            <flux:switch wire:model="status" label="Active" />

            <div class="flex justify-end gap-3">
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
        Posts in it keep their other categories and are not deleted. Any sub-categories move
        up to the top level.
    </x-dashboard.confirm-modal>
</div>
