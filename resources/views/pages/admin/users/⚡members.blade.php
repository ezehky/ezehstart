<?php

use App\Enums\StatusUser;
use App\Models\User;
use App\Traits\WithUserRoleManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination, WithUserRoleManager;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $accountStatus = '';

    public function mount(): void
    {
        kSetSiteTitle('users', 'members');
        kPageGate('users.members');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAccountStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function members()
    {
        return User::query()
            ->members()
            ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
            ->when($this->accountStatus !== '', fn ($query) => $query->where('status', $this->accountStatus))
            ->latest()
            ->paginate(12);
    }

    #[Computed]
    public function statusOptions(): array
    {
        return StatusUser::forSelect();
    }

    #[Computed]
    public function metrics(): array
    {
        $base = fn () => User::query()->members();

        $total = $base()->count();
        $active = $base()->where('status', StatusUser::ACTIVE)->count();
        $unverified = $base()->whereNull('email_verified_at')->count();

        return [
            ['label' => 'Total members', 'value' => number_format($total), 'icon' => 'users', 'tone' => 'sky'],
            ['label' => 'Active accounts', 'value' => number_format($active), 'icon' => 'check-badge', 'tone' => 'emerald'],
            ['label' => 'Unverified email', 'value' => number_format($unverified), 'icon' => 'envelope', 'tone' => 'slate'],
        ];
    }

    protected function afterRoleChange(): void
    {
        unset($this->members, $this->metrics);
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Member metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card
                :label="$metric['label']"
                :value="$metric['value']"
                :icon="$metric['icon']"
                :tone="$metric['tone']"
            />
        @endforeach
    </section>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Members</flux:heading>
                <flux:text class="mt-1">Every account signed up to the member workspace.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search name, email or phone"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="accountStatus">
                    <option value="">All statuses</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <flux:table :paginate="$this->members">
            <flux:table.columns>
                <flux:table.column>Member</flux:table.column>
                <flux:table.column>Phone</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Joined</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->members as $item)
                    <flux:table.row wire:key="member-{{ $item->id }}">
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
                            <flux:badge size="sm" color="zinc">{{ $item->user_type->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-util.status :status="$item->status" />
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->createdAtHuman() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button
                                    icon="eye"
                                    variant="primary"
                                    size="sm"
                                    :href="route('admin.user', $item)"
                                    wire:navigate
                                    title="View profile"
                                />
                                {{-- Moving an account between workspaces is handing out access, so
                                     this asks for full access to Users — the gate the lockout
                                     guard protects. --}}
                                <x-dashboard.gate.button
                                    gate="users"
                                    level="full"
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
                                label="Members"
                                icon="users"
                                text="No members match the current filters."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <x-dashboard.role.modal
        :user="$this->roleUser"
        :roles="$this->assignableRoles"
        :type="$this->pendingAccountType"
        :blocked="$this->accessBlockedReason"
    />
</div>
