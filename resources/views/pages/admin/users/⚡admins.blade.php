<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RoleService;
use App\Traits\WithUserRoleManager;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination, WithUserRoleManager;

    public ?User $admin = null;

    public string $name = '';

    public string $email = '';

    public ?string $phone_number = null;

    public bool $status = true;

    public ?string $password = null;

    /**
     * The role a newly created admin starts on. Empty is allowed and deliberate —
     * an account can be stood up before anybody has decided what it should reach.
     */
    public string $role_id = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $accountStatus = '';

    /**
     * Either a role id, or 'none' for the admins who cannot reach anything. The
     * pending-work queue on the dashboard links straight into 'none'.
     */
    #[Url]
    public string $roleState = '';

    public function mount(): void
    {
        kSetSiteTitle('users', 'admins');
        kPageGate('users.admins');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAccountStatus(): void
    {
        $this->resetPage();
    }

    public function updatedRoleState(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function admins()
    {
        return User::query()
            ->admins()
            ->with('role')
            ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
            ->when($this->accountStatus !== '', fn ($query) => $query->where('status', $this->accountStatus))
            ->when($this->roleState === 'none', fn ($query) => $query->withoutLiveRole())
            ->when($this->roleState !== '' && $this->roleState !== 'none',
                fn ($query) => $query->where('role_id', (int) $this->roleState))
            ->latest()
            ->paginate(10);
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
        $this->resetAdminForm();

        Flux::modal('adminModal')->show();
    }

    public function edit(User $admin): void
    {
        $this->resetValidation();

        $this->admin = $admin;
        $this->name = $admin->name;
        $this->email = $admin->email;
        $this->phone_number = $admin->phone_number;
        $this->status = $admin->status->boolValue();
        $this->password = null;
        $this->role_id = (string) ($admin->role_id ?? '');

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
            'role_id' => ['nullable', Rule::in($this->assignableRoles->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        $logService = app(ActivityLogService::class);
        $roleService = app(RoleService::class);
        $role = $this->role_id === '' ? null : $this->assignableRoles->firstWhere('id', (int) $this->role_id);

        if (! $this->admin) {
            $this->admin = User::make();
            $this->admin->email_verified_at = now();
            // Every account created here is an admin. The type is set on the object
            // rather than through changeType(): there is no account yet to move.
            $this->admin->type = UserTypeEnum::ADMIN;
            $this->admin->role_id = $role?->id;
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

        // On an edit the role moves through the service, so the lockout guard and the
        // activity log both see it. Setting role_id here would slip past both.
        if (! $isNew && $this->admin->role_id !== $role?->id) {
            $reason = $roleService->assignBlockedReason($this->admin, $role);
            $this->respondError($reason ?? '', if: $reason !== null);
        }

        // Nothing changed on an edit: stop here.
        $this->respondPrimary(if: $this->admin->isClean() && $this->admin->exists && $this->admin->role_id === $role?->id);

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

        if (! $isNew) {
            $roleService->assign($this->admin, $role);
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

    private function resetAdminForm(): void
    {
        $this->resetValidation();
        $this->reset('admin', 'name', 'email', 'phone_number', 'status', 'password', 'role_id');
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

            <flux:button variant="primary" icon="plus" wire:click="create">
                Add admin
            </flux:button>
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

        <flux:table :paginate="$this->admins">
            <flux:table.columns>
                <flux:table.column>Admin</flux:table.column>
                <flux:table.column>Phone</flux:table.column>
                <flux:table.column>Role</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Last seen</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->admins as $item)
                    <flux:table.row wire:key="admin-{{ $item->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <x-dashboard.avatar :user="$item" />
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $item->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $item->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->phone_number ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <x-dashboard.user-role :user="$item" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-status :status="$item->status" />
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $item->last_seen_at ? $item->lastSeenAtDiffForHumans() : 'Never' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button
                                    icon="eye"
                                    variant="ghost"
                                    size="sm"
                                    :href="route('admin.user', $item)"
                                    wire:navigate
                                    title="View profile"
                                />
                                <flux:button
                                    icon="pencil-square"
                                    variant="primary"
                                    size="sm"
                                    wire:click="edit({{ $item->id }})"
                                    title="Edit admin"
                                />
                                <flux:button
                                    icon="shield-check"
                                    variant="filled"
                                    size="sm"
                                    wire:click="openRoleManager({{ $item->id }})"
                                    title="Manage access"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <x-dashboard.workspace-no-record
                                label="Admins"
                                icon="shield-check"
                                text="No admin accounts match the current filters."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
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

            <flux:select
                wire:model="role_id"
                label="Role"
                description="What this administrator reaches. Leave it empty to create the account before deciding."
            >
                <option value="">No role yet</option>
                @foreach ($this->assignableRoles as $roleOption)
                    <option value="{{ $roleOption->id }}">{{ $roleOption->name }}</option>
                @endforeach
            </flux:select>

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

    <x-dashboard.user-roles-modal
        :user="$this->roleUser"
        :roles="$this->assignableRoles"
        :type="$this->pendingAccountType"
        :blocked="$this->accessBlockedReason"
    />
</div>
