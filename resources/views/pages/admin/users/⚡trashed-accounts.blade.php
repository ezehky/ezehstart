<?php

use App\Enums\GateAccessEnum;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $restoreId = null;

    public ?int $purgeId = null;

    public function mount(): void
    {
        kSetSiteTitle('users', 'deleted-accounts');
        $this->setPageGate('users.deleted-accounts');
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'name' => $this->columnMaker('Account', locked: true),
            'email' => $this->columnMaker('Anonymized address'),
            'user_type' => $this->columnMaker('Workspace'),
            'created_at' => $this->columnMaker('Joined'),
            'deleted_at' => $this->columnMaker('Deleted', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        $query = app(AccountDeletionService::class)
            ->trashedQuery()
            ->when($this->search !== '', fn (Builder $inner) => $inner->searchMacro(['name', 'email'], $this->search));

        // The date range reads deleted_at here rather than created_at: on this screen
        // "when" means when the account went, not when it arrived.
        return $this->applyDateRange($query, 'deleted_at');
    }

    protected function tableSubject(): string
    {
        return 'deleted accounts';
    }

    protected function tableFilters(): array
    {
        return [
            'search' => $this->filterMaker('Search'),
        ];
    }

    protected function tableDateLabel(): string
    {
        return 'Deleted';
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'created_at' => $item->createdAtHuman(),
            'deleted_at' => $item->deletedAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function tablePerPage(): int
    {
        return 12;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function accounts()
    {
        return $this->applySort($this->tableQuery(), 'deleted_at')->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->accounts;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // RESTORE

    public function confirmRestore(int $userId): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->restoreId = $userId;

        Flux::modal('restoreModal')->show();
    }

    public function restore(): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $user = $this->trashedAccount($this->restoreId);

        $this->respondError('That account is no longer in the deleted list.', if: ! $user);

        app(AccountDeletionService::class)->restore($user);

        Flux::modal('restoreModal')->close();

        $this->reset('restoreId');

        unset($this->accounts, $this->metrics);

        return $this->respondSuccess('The account record is back. Its details are still anonymized.');
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PURGE

    public function confirmPurge(int $userId): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->purgeId = $userId;

        Flux::modal('purgeModal')->show();
    }

    /**
     * What goes with the row, so the dialog can say it rather than imply it.
     */
    #[Computed]
    public function purgeSubject(): ?User
    {
        return $this->trashedAccount($this->purgeId);
    }

    public function purge(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $user = $this->trashedAccount($this->purgeId);

        $this->respondError('That account is no longer in the deleted list.', if: ! $user);

        // Re-read rather than trusted: the dialog was opened against a row that may
        // have been restored in another tab since.
        $service = app(AccountDeletionService::class);
        $reason = $service->purgeBlockedReason($user);
        $this->respondError($reason ?? '', if: $reason !== null);

        $service->purge($user);

        Flux::modal('purgeModal')->close();

        $this->reset('purgeId');

        unset($this->accounts, $this->metrics, $this->purgeSubject);

        return $this->respondSuccess('The account and everything belonging to it has been removed for good.');
    }

    #[Computed]
    public function metrics(): array
    {
        $base = fn () => app(AccountDeletionService::class)->trashedQuery();

        return [
            $this->metricMaker('Deleted accounts', $base()->count(), 'archive-box', tone: 'slate'),
            $this->metricMaker('Gone this month', $base()->where('deleted_at', '>=', now()->startOfMonth())->count(), 'calendar-days'),
        ];
    }

    /**
     * One trashed account by id.
     *
     * Every lookup on this screen goes through here: the default scope hides these
     * rows, so a plain `User::find()` answers null for every one of them and each
     * action would read as "no longer in the list".
     */
    private function trashedAccount(?int $userId): ?User
    {
        if (! $userId) {
            return null;
        }

        return app(AccountDeletionService::class)->trashedQuery()->whereKey($userId)->first();
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-2" aria-label="Deleted account metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card :metric="$metric" />
        @endforeach
    </section>

    <flux:callout icon="information-circle" class="text-sm">
        These accounts were anonymized at the end of their deletion window: the name, email
        address and password were overwritten before the row was kept. The row survives only
        so the history pointing at it — transactions, audit entries — still resolves to
        something. Restoring one brings the record back, not the person's access.
    </flux:callout>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Deleted accounts</flux:heading>
                <flux:text class="mt-1">Anonymized rows, hidden from every other screen.</flux:text>
            </div>

            <flux:input
                class="sm:min-w-60"
                wire:model.live.debounce.350ms="search"
                placeholder="Search name or address"
                icon="magnifying-glass"
            />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-form.date-field
                mode="range"
                wire:model.live="dateFrom"
                end-model="dateTo"
                with-presets
                label="Deleted between"
                class="sm:max-w-md"
            />

            <x-table.column-manager :columns="$this->tableColumnList" />
        </div>

        <x-table.active-filters :filters="$this->tableActiveFilters" />

        <flux:table :paginate="$this->accounts">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                actions
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->accounts as $item)
                    <flux:table.row wire:key="trashed-{{ $item->id }}">
                        <x-table.cell column="name">
                            <div class="font-medium text-slate-500 dark:text-slate-400">{{ $item->name }}</div>
                        </x-table.cell>

                        <x-table.cell column="email">
                            <span class="text-xs text-slate-500">{{ $item->email }}</span>
                        </x-table.cell>

                        <x-table.cell column="user_type">
                            <flux:badge size="sm" color="zinc">{{ $item->user_type->label() }}</flux:badge>
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>
                        <x-table.cell column="deleted_at">{{ $item->deletedAtHuman() }}</x-table.cell>

                        <x-table.cell>
                            <flux:dropdown position="right" align="start">
                                <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                <flux:menu>
                                    <x-dashboard.gate.menu-item
                                        :gate="$pageGate"
                                        :level="$gateModify"
                                        icon="arrow-uturn-left"
                                        wire:click="confirmRestore({{ $item->id }})"
                                    >
                                        Restore record
                                    </x-dashboard.gate.menu-item>

                                    <flux:menu.separator />

                                    <x-dashboard.gate.menu-item
                                        :gate="$pageGate"
                                        :level="$gateFull"
                                        icon="trash"
                                        variant="danger"
                                        wire:click="confirmPurge({{ $item->id }})"
                                    >
                                        Delete for good
                                    </x-dashboard.gate.menu-item>
                                </flux:menu>
                            </flux:dropdown>
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        actions
                        label="Deleted accounts"
                        icon="archive-box"
                        text="No accounts have been deleted, which is the state to hope for."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    {{-- Restore --}}
    <flux:modal name="restoreModal" class="md:w-96">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Restore this record?</flux:heading>
                <flux:text class="mt-2">
                    The row comes back and its history resolves again. The name, address and
                    password were already overwritten, so this does not restore anybody's
                    access — the account stays deleted and cannot be signed in to.
                </flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="restore">Restore record</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Purge --}}
    <flux:modal name="purgeModal" class="md:w-96">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete for good?</flux:heading>
                <flux:text class="mt-2">
                    This removes the row and everything that belongs to it — uploads, ledger
                    entries, audit history. It is the step anonymizing deliberately stopped
                    short of, and it cannot be undone.
                </flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="purge">Delete for good</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
