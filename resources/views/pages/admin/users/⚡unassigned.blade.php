<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Services\ActivityLogService;
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

    public ?User $account = null;

    public string $name = '';

    public string $email = '';

    public ?string $phone_number = null;

    public bool $status = true;

    public ?string $password = null;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        kSetSiteTitle('users', 'unassigned');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function accounts()
    {
        return User::query()
            ->carriesNoRole()
            ->with('userRoles.role')
            ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
            ->latest()
            ->paginate(10);
    }

    /**
     * Roles the account held before they were all deactivated, so an admin can see
     * what to put back.
     */
    public function previousRoles(User $user): string
    {
        return $user->userRoles
            ->map(fn ($userRole) => $userRole->role?->name?->label())
            ->filter()
            ->unique()
            ->implode(', ');
    }

    public function create(): void
    {
        $this->resetAccountForm();

        Flux::modal('accountModal')->show();
    }

    public function edit(User $account): void
    {
        $this->resetValidation();

        $this->account = $account;
        $this->name = $account->name;
        $this->email = $account->email;
        $this->phone_number = $account->phone_number;
        $this->status = $account->status->boolValue();
        $this->password = null;

        Flux::modal('accountModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:50',
                Rule::unique(User::class, 'email')->ignore($this->account?->id),
            ],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['boolean'],
            'password' => [$this->account ? 'nullable' : 'required', 'string', 'min:5'],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        $logService = app(ActivityLogService::class);

        if (! $this->account) {
            $this->account = User::make();
            $this->account->email_verified_at = now();
            $action = ActivityActionEnum::USER_CREATE;
        } else {
            $action = ActivityActionEnum::USER_UPDATE;
        }

        $this->account->name = $this->name;
        $this->account->email = strtolower($this->email);
        $this->account->phone_number = $this->phone_number;
        $this->account->status = StatusUser::tryFrom((int) $this->status);

        if ($this->password) {
            $this->account->password = $this->password;
        }

        $isNew = ! $this->account->exists;

        $this->respondPrimary(if: $this->account->isClean());

        $affectedColumns = $logService->affectedColumns($this->account);

        $this->account->save();

        $logService->logActivity(
            $action,
            "user: {$this->account->name}",
            $affectedColumns,
            $this->account,
        );

        Flux::modal('accountModal')->close();
        $this->resetAccountForm();

        unset($this->accounts);

        return $this->respondSuccess(
            $isNew
                ? 'Account created. Grant it a role to give it access.'
                : 'Account has been successfully updated.'
        );
    }

    protected function afterRoleChange(): void
    {
        unset($this->accounts);
    }

    private function resetAccountForm(): void
    {
        $this->resetValidation();
        $this->reset('account', 'name', 'email', 'phone_number', 'status', 'password');
        $this->status = true;
    }
};
?>

<div class="space-y-6">
    <flux:callout icon="exclamation-triangle" variant="warning">
        <flux:callout.heading>These accounts cannot sign in anywhere</flux:callout.heading>
        <flux:callout.text>
            An account with no active role is blocked from every workspace.
            Grant a role to put it back in circulation.
        </flux:callout.text>
    </flux:callout>

    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Unassigned accounts</flux:heading>
                <flux:text class="mt-1">
                    Accounts holding no role yet, either newly created here or left over after their last role was removed.
                </flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create">
                Add account
            </flux:button>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
            <flux:input
                class="sm:min-w-64"
                wire:model.live.debounce.350ms="search"
                placeholder="Search name, email or phone"
                icon="magnifying-glass"
            />
        </div>

        <flux:table :paginate="$this->accounts">
            <flux:table.columns>
                <flux:table.column>Account</flux:table.column>
                <flux:table.column>Phone</flux:table.column>
                <flux:table.column>Previously held</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Joined</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->accounts as $item)
                    <flux:table.row wire:key="unassigned-{{ $item->id }}">
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
                        <flux:table.cell class="text-slate-500">
                            {{ $this->previousRoles($item) ?: 'Never assigned' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-status :status="$item->status" />
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->createdAtHuman() }}</flux:table.cell>
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
                                    variant="filled"
                                    size="sm"
                                    wire:click="edit({{ $item->id }})"
                                    title="Edit account"
                                />
                                <flux:button
                                    icon="shield-check"
                                    variant="primary"
                                    size="sm"
                                    wire:click="openRoleManager({{ $item->id }})"
                                >
                                    Assign role
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <x-dashboard.workspace-no-record
                                label="Unassigned accounts"
                                icon="check-badge"
                                text="Every account holds at least one role."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="accountModal" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $account === null ? 'Add account' : 'Edit account' }}
                </flux:heading>
                <flux:text class="mt-1">
                    {{ $account === null
                        ? 'The account is created without a role. Assign one from the list to give it access.'
                        : 'Update this account. Assign a role from the list to give it access.' }}
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
                    placeholder="{{ $account ? 'Leave blank to keep current' : 'Set a password' }}"
                    viewable
                />
            </div>

            <flux:switch wire:model="status" label="Active account" description="Allow this account to sign in once it has a role." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save account</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.user-roles-modal :user="$this->roleUser" :matrix="$this->roleMatrix" />
</div>
