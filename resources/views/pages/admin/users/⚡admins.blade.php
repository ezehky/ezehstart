<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RoleService;
use App\Traits\WithDataTable;
use App\Traits\WithUserRoleManager;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination, WithUserRoleManager;

    public ?User $admin = null;

    public string $name = '';

    public string $email = '';

    public ?string $phone_number = null;

    public bool $status = true;

    public ?string $password = null;

    /**
     * The roles a newly created admin starts on, as strings because that is what a
     * checkbox group posts. Empty is allowed and deliberate — an account can be stood
     * up before anybody has decided what it should reach.
     *
     * @var array<int, string>
     */
    public array $roleIds = [];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $accountStatus = '';

    /**
     * Either a role id — matching any admin who holds it, alongside whatever else
     * they hold — or 'none' for the admins who cannot reach anything. The pending-work
     * queue on the dashboard links straight into 'none'.
     */
    #[Url]
    public string $roleState = '';

    public function mount(): void
    {
        kSetSiteTitle('users', 'admins');
        $this->setPageGate('users.admins');
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'name' => ['label' => 'Admin', 'locked' => true, 'sortable' => true],
            'email' => ['label' => 'Email'],
            'phone_number' => ['label' => 'Phone'],
            'roles' => ['label' => 'Role'],
            'status' => ['label' => 'Status', 'sortable' => true],
            'last_seen_at' => ['label' => 'Last seen', 'sortable' => true],
            'created_at' => ['label' => 'Added', 'sortable' => true],
        ];
    }

    protected function tableQuery(): Builder
    {
        $query = User::query()
            ->admins()
            ->with('roles')
            ->when($this->search !== '', fn (Builder $inner) => $inner->searchMacro(['name', 'email', 'phone_number'], $this->search))
            ->when($this->accountStatus !== '', fn (Builder $inner) => $inner->where('status', $this->accountStatus))
            ->when($this->roleState === 'none', fn (Builder $inner) => $inner->withoutLiveRole())
            ->when($this->roleState !== '' && $this->roleState !== 'none',
                fn (Builder $inner) => $inner->holdingRole((int) $this->roleState));

        return $this->applyDateRange($query);
    }

    protected function tableSubject(): string
    {
        return 'admins';
    }

    /**
     * The filters, for the chips that take them off again. A role is named by its
     * own row rather than by an enum, so the chip reads the same list the select is
     * built from.
     */
    protected function tableFilters(): array
    {
        return [
            'search' => ['label' => 'Search'],
            'roleState' => [
                'label' => 'Role',
                'options' => ['none' => 'No live role'] + $this->assignableRoles->pluck('name', 'id')->all(),
            ],
            'accountStatus' => ['label' => 'Status', 'options' => StatusUser::forSelect()],
        ];
    }

    protected function tableDateLabel(): string
    {
        return 'Added';
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            // The whole list, not the first one: an admin holding two roles reaches
            // the union of both, and naming one of them in a file would be a lie.
            'roles' => $item->roles->pluck('name')->implode(', ') ?: 'None',
            'last_seen_at' => $item->last_seen_at ? $item->lastSeenAtHuman() : 'Never',
            'created_at' => $item->createdAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->admins, $this->strandedCount);
    }

    protected function tablePerPage(): int
    {
        return 10;
    }

    public function updatedSearch(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedAccountStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedRoleState(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    #[Computed]
    public function admins()
    {
        return $this->applySort($this->tableQuery(), 'created_at')->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->admins;
    }

    /**
     * How many admins cannot reach anything, for the callout above the table. Counted
     * rather than read off the page: the answer is about the whole install, and a
     * filtered page would keep changing it.
     */
    #[Computed]
    public function strandedCount(): int
    {
        return User::query()->withoutLiveRole()->count();
    }

    #[Computed]
    public function statusOptions(): array
    {
        return StatusUser::forSelect();
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetAdminForm();

        Flux::modal('adminModal')->show();
    }

    public function edit(User $admin): void
    {
        $this->checkGate();

        $this->resetValidation();

        $this->admin = $admin;
        $this->name = $admin->name;
        $this->email = $admin->email;
        $this->phone_number = $admin->phone_number;
        $this->status = $admin->status->boolValue();
        $this->password = null;
        $this->roleIds = $admin->roles->pluck('id')->map(fn ($id) => (string) $id)->all();

        Flux::modal('adminModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:50',
                Rule::unique(User::class, 'email')->ignore($this->admin?->id),
            ],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['boolean'],
            'password' => [$this->admin ? 'nullable' : 'required', 'string', 'min:5'],
            'roleIds' => ['array'],
            'roleIds.*' => [Rule::in($this->assignableRoles->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ];
    }

    public function save(): bool
    {
        // The button is hidden either way, which stops nobody who can open a console.
        $this->checkGate($this->admin ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);

        $this->validate();

        $logService = app(ActivityLogService::class);
        $roleService = app(RoleService::class);
        $roles = $this->pendingFormRoles();

        if (! $this->admin) {
            $this->admin = User::make();
            $this->admin->email_verified_at = now();
            // Every account created here is an admin. The type is set on the object
            // rather than through changeType(): there is no account yet to move.
            $this->admin->user_type = UserTypeEnum::ADMIN;
            $action = ActivityActionEnum::USER_CREATE;
        } else {
            $action = ActivityActionEnum::USER_UPDATE;
        }

        $this->admin->name = $this->name;
        $this->admin->email = strtolower($this->email);
        $this->admin->phone_number = $this->phone_number;
        $this->admin->status = StatusUser::tryFrom((int) $this->status);

        if ($this->password) {
            $this->admin->password = $this->password;
        }

        $isNew = ! $this->admin->exists;
        $rolesChanged = $isNew
            ? $roles->isNotEmpty()
            : $this->admin->roles->pluck('id')->sort()->values()->all() !== $roles->pluck('id')->sort()->values()->all();

        // On an edit the roles move through the service, so the lockout guard and the
        // activity log both see them. Writing the pivot here would slip past both.
        if (! $isNew && $rolesChanged) {
            $reason = $roleService->assignBlockedReason($this->admin, $roles);
            $this->respondError($reason ?? '', if: $reason !== null);
        }

        // Nothing changed on an edit: stop here.
        $this->respondPrimary(if: $this->admin->isClean() && $this->admin->exists && ! $rolesChanged);

        $affectedColumns = $logService->affectedColumns($this->admin);

        if ($this->admin->isDirty()) {
            $this->admin->save();

            $logService->logActivity(
                $action,
                "admin: {$this->admin->name}",
                $affectedColumns,
                $this->admin,
            );
        }

        // A brand-new account goes through the service too, for the same reason: the
        // pivot write, the lockout guard and the log entry are one step, not three.
        if ($rolesChanged) {
            $roleService->syncRoles($this->admin, $roles);
        }

        Flux::modal('adminModal')->close();
        $this->resetAdminForm();

        unset($this->admins, $this->strandedCount);

        return $this->respondSuccess($isNew ? 'Admin has been successfully created.' : 'Admin has been successfully updated.');
    }

    protected function afterRoleChange(): void
    {
        unset($this->admins, $this->strandedCount);
    }

    /**
     * The roles ticked on the admin form, resolved against the assignable list rather
     * than fetched: an id that is not on offer is not a role, whatever was posted.
     *
     * @return Collection<int, Role>
     */
    private function pendingFormRoles(): Collection
    {
        return collect($this->roleIds)
            ->map(fn ($id) => $this->assignableRoles->firstWhere('id', (int) $id))
            ->filter()
            ->unique('id')
            ->values();
    }

    private function resetAdminForm(): void
    {
        $this->resetValidation();
        $this->reset('admin', 'name', 'email', 'phone_number', 'status', 'password', 'roleIds');
        $this->status = true;
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Admins</flux:heading>
                <flux:text class="mt-1">
                    Everyone with access to this administration workspace, and the role each one carries.
                </flux:text>
            </div>

            <x-dashboard.gate.button
                :gate="$pageGate"
                :level="$gateCreate"
                variant="primary"
                icon="plus"
                wire:click="create"
            >
                Add admin
            </x-dashboard.gate.button>
        </div>

        @if ($this->strandedCount && $this->roleState !== 'none')
            <flux:callout icon="exclamation-triangle" color="amber">
                <flux:callout.heading>
                    {{ kPluralize('admin account', $this->strandedCount) }} cannot reach anything
                </flux:callout.heading>
                <flux:callout.text>
                    They have no role, or one that has been switched off, so they sign in to an
                    empty workspace.
                    <flux:link wire:click="$set('roleState', 'none')" class="cursor-pointer">Show them</flux:link>.
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
            <flux:input
                class="sm:min-w-64"
                wire:model.live.debounce.350ms="search"
                placeholder="Search name, email or phone"
                icon="magnifying-glass"
            />
            <flux:select wire:model.live="roleState">
                <option value="">All roles</option>
                <option value="none">No live role</option>
                @foreach ($this->assignableRoles as $roleOption)
                    <option value="{{ $roleOption->id }}">{{ $roleOption->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="accountStatus">
                <option value="">All statuses</option>
                @foreach ($this->statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
        </div>

        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
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

        <x-table.bulk-bar class="mb-5" :count="$this->selectedCount" :total="$this->tableTotalCount" :matching="$selectMatching" :columns="$this->tableExportOptions" subject="admins" />

        <flux:table :paginate="$this->admins">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                selectable
                actions
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->admins as $item)
                    <flux:table.row wire:key="admin-{{ $item->id }}">
                        <x-table.select :id="$item->id" />

                        <x-table.cell column="name" :href="route('admin.user', $item)">
                            <div class="flex items-center gap-3">
                                <x-dashboard.avatar :user="$item" />
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $item->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $item->email }}</div>
                                </div>
                            </div>
                        </x-table.cell>

                        <x-table.cell column="email">{{ $item->email }}</x-table.cell>
                        <x-table.cell column="phone_number">{{ $item->phone_number ?: '—' }}</x-table.cell>

                        <x-table.cell column="roles">
                            <x-dashboard.role.badges :user="$item" />
                        </x-table.cell>

                        <x-table.cell column="status">
                            <x-util.e-badge :enum="$item->status" />
                        </x-table.cell>

                        <x-table.cell column="last_seen_at">
                            {{ $item->last_seen_at ? $item->lastSeenAtDiffForHumans() : 'Never' }}
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>

                        <x-table.cell class="flex gap-2">
                            <flux:button
                                icon="eye"
                                variant="ghost"
                                size="sm"
                                :href="route('admin.user', $item)"
                                wire:navigate
                                title="View profile"
                            />
                            <x-dashboard.gate.button
                                :gate="$pageGate"
                                :level="$gateModify"
                                icon="pencil-square"
                                variant="primary"
                                size="sm"
                                wire:click="edit({{ $item->id }})"
                                title="Edit admin"
                            />
                            {{-- Handing out roles is handing out access, so this one asks for full
                                    access to Users — the same gate the lockout guard protects. --}}
                            <x-dashboard.gate.button
                                gate="users"
                                :level="$gateFull"
                                icon="shield-check"
                                variant="filled"
                                size="sm"
                                wire:click="openRoleManager({{ $item->id }})"
                                title="Manage access"
                            />
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        selectable
                        actions
                        label="Admins"
                        icon="shield-check"
                        text="No admin accounts match the current filters."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="adminModal" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $admin === null ? 'Add admin' : 'Edit admin' }}
                </flux:heading>
                <flux:text class="mt-1">
                    {{ $admin === null
                        ? 'The new account signs in to the administration workspace and reaches whatever its role grants.'
                        : 'Update this account. Use Manage access to move it out of the admin workspace entirely.' }}
                </flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input label="Name" wire:model="name" placeholder="e.g. Jane Doe" autofocus badge="required" />

                <flux:input type="email" label="Email" wire:model="email" placeholder="jane@example.com" badge="required" />

                <flux:input label="Phone number" wire:model="phone_number" placeholder="e.g. +234 800 000 0000" />

                <flux:input
                    type="password"
                    label="Password"
                    wire:model="password"
                    placeholder="{{ $admin ? 'Leave blank to keep current' : 'Set a password' }}"
                    viewable
                />
            </div>

            <flux:checkbox.group
                wire:model="roleIds"
                label="Roles"
                description="What this administrator reaches. Holding more than one adds the access up; tick none to create the account before deciding."
            >
                @forelse ($this->assignableRoles as $roleOption)
                    <flux:checkbox
                        :value="(string) $roleOption->id"
                        :label="$roleOption->name"
                        :description="$roleOption->description"
                    />
                @empty
                    <flux:text class="text-sm">
                        No live roles exist yet. Create one on the Roles screen first.
                    </flux:text>
                @endforelse
            </flux:checkbox.group>

            <div class="space-y-4">
                <flux:switch wire:model="status" label="Active account" description="Allow this admin to sign in." />
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save admin</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.role.modal
        :user="$this->roleUser"
        :roles="$this->assignableRoles"
        :type="$this->pendingAccountType"
        :blocked="$this->accessBlockedReason"
    />
</div>
