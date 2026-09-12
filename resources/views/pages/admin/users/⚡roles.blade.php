<?php

use App\Enums\GateAccessEnum;
use App\Models\Role;
use App\Services\GateService;
use App\Services\RoleService;
use App\Traits\WithDataTable;
use App\Traits\WithGateManager;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithDataTable, WithGateManager;

    public ?Role $role = null;

    public string $name = '';

    public ?string $description = null;

    public bool $active = true;

    public ?int $deleteId = null;

    public function mount(): void
    {
        kSetSiteTitle('users', 'roles');
        $this->setPageGate('users.roles');
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'name' => $this->columnMaker('Role', locked: true, sortable: true),
            'description' => $this->columnMaker('What it can do'),
            'gates' => $this->columnMaker('Access', exportable: false),
            'users_count' => $this->columnMaker('Admins', exportable: false),
            'created_at' => $this->columnMaker('Added', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        return Role::query()->withCount('users');
    }

    protected function tableSubject(): string
    {
        return 'roles';
    }

    /**
     * Every role is on the page at once, so the header checkbox takes all of them.
     *
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->roles;
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'created_at' => $item->createdAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    #[Computed]
    public function roles(): Collection
    {
        // The protected role first whatever else is sorted on: it is the one that
        // keeps the install administrable, and it belongs at the top of the list.
        return $this->applySort(
            $this->tableQuery()->orderByDesc('is_protected'),
            'name',
            'asc'
        )->get();
    }

    /**
     * How many screens each role reaches, for the listing.
     *
     * Counted off the stored map rather than the resolved one: this column is about
     * the role itself, and an administrator's personal override is not the role's
     * business. Keys that are no longer gateable are dropped, so a screen removed
     * from the sidebar stops being counted without anybody editing the role.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function gateCounts(): array
    {
        $service = app(GateService::class);

        return $this->roles
            ->mapWithKeys(fn (Role $role) => [
                $role->id => \count($service->normalize($role->gatesArray())),
            ])
            ->all();
    }

    #[Computed]
    public function gateTotal(): int
    {
        return \count(app(GateService::class)->keys());
    }

    /**
     * How far this account may go on this screen, asked once and read by every
     * button and every write.
     */
    #[Computed]
    public function access(): GateAccessEnum
    {
        return kGateAccess('users.roles');
    }

    public function create(): void
    {
        $this->respondError(
            'You do not have access to add roles.',
            if: ! $this->access->covers(GateAccessEnum::CREATE),
        );

        $this->resetRoleForm();

        Flux::modal('roleModal')->show();
    }

    public function edit(Role $role): void
    {
        $this->respondError(
            'You do not have access to edit roles.',
            if: ! $this->access->covers(GateAccessEnum::MODIFY),
        );

        $this->resetValidation();

        $this->role = $role;
        $this->name = $role->name;
        $this->description = $role->description;
        $this->active = $role->status->isActive();

        Flux::modal('roleModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        // The button is hidden either way, which stops nobody who can open a console.
        $this->respondError(
            'You do not have access to save roles.',
            if: ! $this->access->covers($this->role ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE),
        );

        $this->validate();

        $service = app(RoleService::class);

        if (! $this->role) {
            $role = $service->create($this->name, $this->description);

            Flux::modal('roleModal')->close();
            $this->resetRoleForm();

            unset($this->roles, $this->gateCounts);

            return $this->respondSuccess("The {$role->name} role has been created. Give it access next.");
        }

        // The guard's own wording, rather than a generic failure.
        $reason = $service->updateBlockedReason($this->role, $this->active);
        $this->respondError($reason ?? '', if: $reason !== null);

        $this->respondPrimary(if: ! $service->update($this->role, $this->name, $this->description, $this->active));

        Flux::modal('roleModal')->close();
        $this->resetRoleForm();

        unset($this->roles, $this->gateCounts);

        return $this->respondSuccess('The role has been updated.');
    }

    public function confirmDelete(int $roleId): void
    {
        $this->respondError(
            'You do not have access to delete roles.',
            if: ! $this->access->covers(GateAccessEnum::FULL),
        );

        $this->deleteId = $roleId;

        Flux::modal('deleteModal')->show();
    }

    #[Computed]
    public function deleteBlockedReason(): ?string
    {
        if (! $role = Role::query()->whereKey($this->deleteId)->first()) {
            return null;
        }

        return app(RoleService::class)->deleteBlockedReason($role);
    }

    public function delete(): bool
    {
        $this->respondError(
            'You do not have access to delete roles.',
            if: ! $this->access->covers(GateAccessEnum::FULL),
        );

        $role = Role::query()->whereKey($this->deleteId)->first();

        $this->respondError('That role no longer exists.', if: ! $role);

        // Re-read rather than trusted: accounts may have moved onto it since the
        // dialog was opened, and the count in the warning is not the check.
        $service = app(RoleService::class);
        $reason = $service->deleteBlockedReason($role);
        $this->respondError($reason ?? '', if: $reason !== null);

        $service->delete($role);

        Flux::modal('deleteModal')->close();

        $this->reset('deleteId');

        unset($this->roles, $this->gateCounts, $this->deleteBlockedReason);

        return $this->respondSuccess('The role has been deleted.');
    }

    protected function afterGateChange(): void
    {
        unset($this->roles, $this->gateCounts, $this->access);
    }

    private function resetRoleForm(): void
    {
        $this->resetValidation();
        $this->reset('role', 'name', 'description', 'active');
        $this->active = true;
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Roles</flux:heading>
                <flux:text class="mt-1">
                    How the administration workspace is divided up. users carry no role — only
                    admin accounts do, and one account can hold several. Their access adds up.
                </flux:text>
            </div>

            <x-dashboard.gate.button gate="users.roles" level="create" variant="primary" icon="plus" wire:click="create">
                Add role
            </x-dashboard.gate.button>
        </div>

        <flux:table>
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                actions
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->roles as $item)
                    <flux:table.row wire:key="role-{{ $item->id }}">
                        <x-table.cell column="name">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <flux:badge size="sm" :color="$item->is_protected ? 'purple' : 'blue'">
                                    {{ $item->name }}
                                </flux:badge>

                                @unless ($item->status->isActive())
                                    <flux:badge size="sm" color="amber">Off</flux:badge>
                                @endunless

                                @if ($item->is_protected)
                                    <flux:tooltip content="Cannot be deleted or switched off — it is what keeps this install administrable.">
                                        <flux:icon name="lock-closed" class="size-4 text-slate-400" />
                                    </flux:tooltip>
                                @endif
                            </div>
                        </x-table.cell>

                        <x-table.cell column="description" class="max-w-md text-slate-500 dark:text-slate-400">
                            {{ $item->description ?: '—' }}
                        </x-table.cell>

                        <x-table.cell column="gates">
                            @php($granted = data_get($this->gateCounts, $item->id, 0))
                            @if ($granted)
                                <flux:badge size="sm" :color="$granted === $this->gateTotal ? 'green' : 'blue'">
                                    {{ $granted }} of {{ $this->gateTotal }} screens
                                </flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">No screens</flux:badge>
                            @endif
                        </x-table.cell>

                        <x-table.cell column="users_count" class="font-medium">{{ number_format($item->users_count) }}</x-table.cell>
                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>

                        <x-table.cell>
                            <div class="flex flex-wrap gap-1">
                                {{-- Editing a role's map is handing out access, so it asks for full
                                     access to Users — the same gate the lockout guard protects. --}}
                                <x-dashboard.gate.button
                                    gate="users"
                                    level="full"
                                    icon="shield-check"
                                    variant="ghost"
                                    size="sm"
                                    wire:click="openRoleGates({{ $item->id }})"
                                >
                                    Manage access
                                </x-dashboard.gate.button>

                                <x-dashboard.gate.button
                                    gate="users.roles"
                                    level="modify"
                                    icon="pencil-square"
                                    variant="ghost"
                                    size="sm"
                                    wire:click="edit({{ $item->id }})"
                                    title="Edit role"
                                />

                                @unless ($item->is_protected)
                                    <x-dashboard.gate.button
                                        gate="users.roles"
                                        level="full"
                                        icon="trash"
                                        variant="ghost"
                                        size="sm"
                                        wire:click="confirmDelete({{ $item->id }})"
                                        title="Delete role"
                                    />
                                @endunless
                            </div>
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        actions
                        label="Roles"
                        icon="identification"
                        text="No roles exist yet. Add one to start assigning administrators."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="roleModal" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $role === null ? 'Add role' : 'Edit role' }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $role === null
                        ? 'A new role starts with no access at all. Grant it screens from Manage access once it exists.'
                        : 'Rename the role or switch it off. Use Manage access to change what it reaches.' }}
                </flux:text>
            </div>

            <flux:input label="Name" wire:model="name" placeholder="e.g. Media" autofocus badge="required" />

            <flux:textarea
                label="What it can do"
                wire:model="description"
                rows="2"
                placeholder="One line, for the people handing this role out."
            />

            @if ($role)
                <flux:switch
                    wire:model="active"
                    label="Active role"
                    description="Switching a role off takes its access away from everybody on it, without unpicking who holds what."
                    :disabled="$role->is_protected"
                />
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save role</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteModal"
        title="Delete this role?"
        confirm="Delete role"
        confirm-icon="trash"
        wire:click="delete"
    >
        @if ($this->deleteBlockedReason)
            {{ $this->deleteBlockedReason }}
        @else
            The role and its access map go for good. Nobody is on it, so no account loses access.
        @endif
    </x-dashboard.confirm-modal>

    <x-dashboard.gate.modal
        :rows="$gateRows"
        :subject="$this->gateSubjectLabel()"
    />
</div>
