<?php

use App\Enums\StatusUser;
use App\Models\User;
use App\Traits\WithDataTable;
use App\Traits\WithUserRoleManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination, WithUserRoleManager;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $accountStatus = '';

    public function mount(): void
    {
        kSetSiteTitle('users', 'users-list');
        $this->setPageGate('users.users-list');
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'name' => ['label' => 'Member', 'locked' => true, 'sortable' => true],
            'email' => ['label' => 'Email'],
            'phone_number' => ['label' => 'Phone'],
            'user_type' => ['label' => 'Type'],
            'status' => ['label' => 'Status', 'sortable' => true],
            'created_at' => ['label' => 'Joined', 'sortable' => true],
        ];
    }

    protected function tableQuery(): Builder
    {
        $query = User::query()
            ->users()
            ->when($this->search !== '', fn (Builder $inner) => $inner->searchMacro(['name', 'email', 'phone_number'], $this->search))
            ->when($this->accountStatus !== '', fn (Builder $inner) => $inner->where('status', $this->accountStatus));

        return $this->applyDateRange($query);
    }

    protected function tableSubject(): string
    {
        return 'members';
    }

    /**
     * The filters, for the chips that take them off again.
     */
    protected function tableFilters(): array
    {
        return [
            'search' => ['label' => 'Search'],
            'accountStatus' => ['label' => 'Status', 'options' => StatusUser::forSelect()],
        ];
    }

    protected function tableDateLabel(): string
    {
        return 'Joined';
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'created_at' => $item->createdAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->users, $this->metrics);
    }

    protected function tablePerPage(): int
    {
        return 12;
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

    #[Computed]
    public function users()
    {
        return $this->applySort($this->tableQuery(), 'created_at')->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->users;
    }

    #[Computed]
    public function statusOptions(): array
    {
        return StatusUser::forSelect();
    }

    #[Computed]
    public function metrics(): array
    {
        $base = fn () => User::query()->users();

        $total = $base()->count();
        $active = $base()->where('status', StatusUser::ACTIVE)->count();
        $unverified = $base()->whereNull('email_verified_at')->count();

        return [
            ['label' => 'Total users', 'value' => number_format($total), 'icon' => 'users', 'tone' => 'sky'],
            ['label' => 'Active accounts', 'value' => number_format($active), 'icon' => 'check-badge', 'tone' => 'emerald'],
            ['label' => 'Unverified email', 'value' => number_format($unverified), 'icon' => 'envelope', 'tone' => 'slate'],
        ];
    }

    protected function afterRoleChange(): void
    {
        unset($this->users, $this->metrics);
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

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-form.date-field
                mode="range"
                wire:model.live="dateFrom"
                end-model="dateTo"
                with-presets
                label="Joined between"
                class="sm:max-w-md"
            />

            <x-table.column-manager :columns="$this->tableColumnList" />
        </div>

        <x-table.active-filters :filters="$this->tableActiveFilters" />

        <x-table.bulk-bar
            :count="$this->selectedCount"
            :total="$this->tableTotalCount"
            :matching="$selectMatching"
            :columns="$this->tableExportOptions"
            subject="members"
        />

        <flux:table :paginate="$this->users">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                selectable
                actions
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->users as $item)
                    <flux:table.row wire:key="member-{{ $item->id }}">
                        <x-table.select :id="$item->id" />

                        {{-- The member's own row opens their record, so the name is
                             the link rather than a button at the far end of it. --}}
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

                        <x-table.cell column="user_type">
                            <flux:badge size="sm" color="zinc">{{ $item->user_type->label() }}</flux:badge>
                        </x-table.cell>

                        <x-table.cell column="status">
                            <x-util.e-badge :enum="$item->status" />
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>

                        <x-table.cell class="flex gap-2">
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
                        label="Members"
                        icon="users"
                        text="No members match the current filters."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    <x-dashboard.role.modal
        :user="$this->roleUser"
        :roles="$this->assignableRoles"
        :type="$this->pendingAccountType"
        :blocked="$this->accessBlockedReason"
    />
</div>
