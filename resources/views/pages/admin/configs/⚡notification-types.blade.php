<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Models\NotificationType;
use App\Services\ActivityLogService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithDataTable;

    public ?NotificationType $notificationType = null;

    public string $notification_type = '';

    public string $title = '';

    public ?string $description = null;

    public int $flow_order = 0;

    public bool $status = true;

    /** The type queued for deletion, held while the dialog asks. */
    public ?int $deleteId = null;

    public function mount(): void
    {
        kSetSiteTitle('config', 'notification types');
        $this->setPageGate('config.notification-types');
    }

    /**
     * @return Collection<int, NotificationType>
     */
    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'title' => $this->columnMaker('Type', locked: true, sortable: true),
            'notification_type' => $this->columnMaker('Key', sortable: true),
            'notification_preferences_count' => $this->columnMaker('Subscribed', exportable: false),
            'flow_order' => $this->columnMaker('Order', sortable: true),
            'status' => $this->columnMaker('Status', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        return NotificationType::query()->withCount('notificationPreferences');
    }

    protected function tableSubject(): string
    {
        return 'notification types';
    }

    /**
     * Every type is on the page at once, so the header checkbox takes all of them.
     *
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->types;
    }

    #[Computed]
    public function types(): Collection
    {
        return $this->applySort($this->tableQuery(), 'flow_order', 'asc')->get();
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetForm();

        $this->flow_order = (int) NotificationType::query()->max('flow_order') + 1;

        Flux::modal('typeModal')->show();
    }

    public function edit(NotificationType $notificationType): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->resetForm();

        $this->notificationType = $notificationType;
        $this->fill($notificationType->only(['notification_type', 'title', 'description', 'flow_order']));
        $this->status = $notificationType->status->isActive();

        Flux::modal('typeModal')->show();
    }

    public function updatedTitle(string $value): void
    {
        // The key is derived from the title on a new row, because it is an
        // identifier the code may one day want to match on and hand-typed keys
        // drift into inconsistency immediately.
        if (! $this->notificationType) {
            $this->notification_type = kSlug($value);
        }
    }

    protected function rules(): array
    {
        return [
            'notification_type' => [
                'required',
                'string',
                'max:100',
                Rule::unique(NotificationType::class, 'notification_type')->ignore($this->notificationType?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'flow_order' => ['required', 'integer', 'min:0'],
            'status' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->validate();

        $action = ActivityActionEnum::NOTIFICATION_TYPE_UPDATE;

        if (! $this->notificationType) {
            $this->notificationType = NotificationType::make();
            $action = ActivityActionEnum::NOTIFICATION_TYPE_CREATE;
        }

        $this->notificationType->notification_type = kSlug($this->notification_type);
        $this->notificationType->title = $this->title;
        $this->notificationType->description = $this->description;
        $this->notificationType->flow_order = $this->flow_order;
        $this->notificationType->status = StatusDefault::from((int) $this->status);

        $this->respondPrimary(if: $this->notificationType->isClean());

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->notificationType);

        $this->notificationType->save();

        $serviceInstance->logActivity(
            $action,
            " notification type: {$this->notificationType->title}",
            $affectedColumns,
            model: $this->notificationType,
        );

        Flux::modal('typeModal')->close();
        $this->resetForm();
        unset($this->types);

        return $this->respondSuccess('The notification type has been saved.');
    }

    public function confirmDelete(int $typeId): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->deleteId = $typeId;

        Flux::modal('deleteTypeModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $type = NotificationType::query()->whereKey($this->deleteId)->first();

        abort_unless((bool) $type, 404);

        $description = " notification type: {$type->title}";

        // Every member's switch for this type goes with it, by cascade. Leaving
        // orphaned preferences for something nobody can send would only make the
        // settings page render options that do nothing.
        $type->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::NOTIFICATION_TYPE_DELETE, $description);

        Flux::modal('deleteTypeModal')->close();
        $this->reset('deleteId');
        unset($this->types);

        return $this->respondSuccess('The notification type has been deleted.');
    }

    private function resetForm(): void
    {
        $this->reset('notificationType', 'notification_type', 'title', 'description', 'flow_order', 'status');
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Notification types</flux:heading>
                <flux:text class="mt-1">
                    What users can opt in and out of. These are rows rather than code, so a
                    new one can be added without a deploy — every account picks up a switch
                    for it on their next visit.
                </flux:text>
            </div>

            <x-dashboard.gate.button :gate="$pageGate" :level="$gateCreate" variant="primary" icon="plus" wire:click="create">
                New type
            </x-dashboard.gate.button>
        </div>

        @if ($this->types->isEmpty())
            <x-dashboard.workspace-no-record
                icon="bell"
                label="No types yet"
                text="Run the seeder or add the first one by hand."
            />
        @else
            <flux:table>
                <x-table.columns
                    :columns="$this->tableColumnList"
                    :sort="$sortColumn"
                    :direction="$sortDirection"
                    actions
                    actions-label=""
                />

                <x-table.rows :columns="$this->tableColumnList">
                    @forelse ($this->types as $item)
                        <flux:table.row wire:key="type-{{ $item->id }}">
                            <x-table.cell column="title">
                                <p class="font-medium text-slate-950 dark:text-white">{{ $item->title }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->description }}</p>
                            </x-table.cell>

                            <x-table.cell column="notification_type">
                                <span class="font-mono text-xs">{{ $item->notification_type }}</span>
                                @unless ($item->knownType())
                                    {{-- Added by an administrator: no code refers to it by name. --}}
                                    <flux:badge size="sm" color="amber" inset="top bottom">Custom</flux:badge>
                                @endunless
                            </x-table.cell>

                            <x-table.cell column="notification_preferences_count">{{ number_format($item->notification_preferences_count) }}</x-table.cell>
                            <x-table.cell column="flow_order"><span class="tabular-nums">{{ $item->flow_order }}</span></x-table.cell>
                            <x-table.cell column="status"><x-util.e-badge :enum="$item->status" /></x-table.cell>

                            <x-table.cell class="flex justify-end gap-1">
                                <x-dashboard.gate.button
                                    :gate="$pageGate"
                                    :level="$gateModify"
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil-square" wire:click="edit({{ $item->id }})"
                                />
                                <x-dashboard.gate.button
                                    :gate="$pageGate"
                                    :level="$gateFull"
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="confirmDelete({{ $item->id }})"
                                />
                            </x-table.cell>
                        </flux:table.row>
                    @empty
                        <x-table.empty
                            :columns="$this->tableColumnList"
                            actions
                            label="Notification types"
                            icon="bell"
                            text="No type has been added yet."
                        />
                    @endforelse
                </x-table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal name="typeModal" class="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $notificationType ? 'Edit type' : 'New type' }}</flux:heading>

            <flux:input wire:model.blur="title" label="Title" placeholder="Product updates" />
            <flux:input
                wire:model="notification_type"
                label="Key"
                description="The identifier code matches on. Changing it on an existing type orphans anything referring to it."
            />
            <flux:textarea wire:model="description" label="Description" rows="2" description="Shown under the switch on the member's settings page." />
            <flux:input type="number" wire:model="flow_order" label="Order" />
            <flux:switch wire:model="status" label="Offered to users" description="Turning this off silences the type for everybody, whatever their own switch says." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteTypeModal"
        title="Delete this notification type?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        Every member's preference for it is removed with it. If you only want to stop sending
        this notification, turn it off instead — that keeps everybody's choice for later.
    </x-dashboard.confirm-modal>
</div>
