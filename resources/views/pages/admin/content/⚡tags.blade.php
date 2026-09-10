<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Models\Tag;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithFormResponseMessage, WithPagination;

    public ?Tag $tag = null;

    public string $name = '';

    public bool $status = true;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        kSetSiteTitle('content', 'tags');
    }

    #[Computed]
    public function tags()
    {
        return Tag::query()
            ->when($this->search, fn (Builder $query) => $query->searchMacro('name', $this->search))
            ->alphabetical()
            ->paginate(20);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('tagModal')->show();
    }

    public function edit(Tag $tag): void
    {
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
        $this->tag = $tag;

        Flux::modal('deleteTagModal')->show();
    }

    public function delete(): bool
    {
        $this->respondError('Select a tag to delete first.', if: ! $this->tag);

        $description = " tag: {$this->tag->name}";

        $this->tag->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::TAG_DELETE, $description);

        Flux::modal('deleteTagModal')->close();
        $this->resetForm();
        unset($this->tags);

        return $this->respondSuccess('The tag has been deleted.');
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
                <flux:button variant="primary" icon="plus" wire:click="create">New tag</flux:button>
            </div>
        </div>

        @if ($this->tags->isEmpty())
            <x-dashboard.workspace-no-record
                icon="hashtag"
                label="No tags yet"
                text="They appear here as soon as an author uses one."
            />
        @else
            <flux:table :paginate="$this->tags">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->tags as $item)
                        <flux:table.row wire:key="tag-{{ $item->id }}">
                            <flux:table.cell>{{ $item->name }}</flux:table.cell>
                            <flux:table.cell><x-status :status="$item->status" /></flux:table.cell>
                            <flux:table.cell class="flex justify-end gap-1">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" />
                                <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $item->id }})" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
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
