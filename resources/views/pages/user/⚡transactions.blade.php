<?php

use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Traits\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination;

    public User $user;

    #[Url]
    public string $group = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->user = auth()->user();

        kSetSiteTitle('transactions');
    }

    /**
     * The listing as a table, for WithDataTable.
     *
     * No page gate is named: kPageGate() lets a non-admin route through, and gates
     * divide up the admin workspace only. The scoping that matters here is the
     * relationship the query is built on.
     */
    protected function tableColumns(): array
    {
        return [
            'reference' => ['label' => 'Reference', 'locked' => true, 'sortable' => true],
            'description' => ['label' => 'Description'],
            'amount' => ['label' => 'Amount', 'sortable' => true, 'summary' => 'sum', 'money' => true],
            'balance' => ['label' => 'Balance after', 'exportable' => false],
            'status' => ['label' => 'Status', 'sortable' => true],
            'created_at' => ['label' => 'Date', 'sortable' => true],
        ];
    }

    /**
     * Built off the account's own relationship, so every re-run — the totals, the
     * export, the selection — is the same narrow question rather than the ledger.
     */
    protected function tableQuery(): Builder
    {
        // Transaction::query() rather than the relationship: a HasMany is not an
        // Eloquent\Builder, and every re-run of this — the totals, the export, the
        // selection — hands it to the trait as one.
        $query = Transaction::query()
            ->where('user_id', $this->user->id)
            ->with(['charges', 'balance'])
            ->when($this->group !== '', fn (Builder $inner) => $inner->where('transaction_group', $this->group))
            ->when($this->status !== '', fn (Builder $inner) => $inner->where('status', (int) $this->status));

        return $this->applyDateRange($query);
    }

    protected function tableSubject(): string
    {
        return 'transactions';
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'amount' => $item->signedAmount(),
            'created_at' => $item->createdDatetimeHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function tablePerPage(): int
    {
        return 15;
    }

    public function updatedGroup(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    #[Computed]
    public function transactions()
    {
        return $this->applySort($this->tableQuery(), 'created_at')
            ->orderByDesc('id')
            ->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->transactions;
    }

    #[Computed]
    public function balance(): float
    {
        return app(TransactionService::class)->balanceFor($this->user);
    }

    /**
     * @return array<int, array{label: string, value: string, icon: string, tone: string}>
     */
    #[Computed]
    public function metrics(): array
    {
        $pending = Transaction::query()
            ->where('user_id', $this->user->id)
            ->pending()
            ->count();

        return [
            [
                'label' => 'Balance',
                'value' => kMoneyFormat($this->balance, decodeHtml: true),
                'icon' => 'banknotes',
                'tone' => 'emerald',
            ],
            [
                'label' => 'Transactions',
                'value' => number_format($this->user->transactions()->count()),
                'icon' => 'receipt-percent',
                'tone' => 'sky',
            ],
            [
                'label' => 'Awaiting review',
                'value' => number_format($pending),
                'icon' => 'clock',
                'tone' => $pending > 0 ? 'amber' : 'slate',
            ],
        ];
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Balance summary">
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
                <flux:heading level="2" size="lg">Your statement</flux:heading>
                <flux:text class="mt-1">Every movement on your account, newest first.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:select wire:model.live="group" class="sm:min-w-44">
                    <flux:select.option value="">All types</flux:select.option>
                    @foreach (TransactionGroupEnum::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="status" class="sm:min-w-40">
                    <flux:select.option value="">All statuses</flux:select.option>
                    @foreach (StatusTransaction::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->transactions->isEmpty())
            <x-dashboard.workspace-no-record
                icon="receipt-percent"
                label="Nothing here yet"
                text="Once money moves on your account, every movement shows up on this page."
            />
        @else
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <x-form.date-field
                    mode="range"
                    wire:model.live="dateFrom"
                    end-model="dateTo"
                    with-presets
                    label="Dated between"
                    class="sm:max-w-md"
                />

                <x-table.column-manager :columns="$this->tableColumnList" />
            </div>

            {{-- Export only: a member takes a copy of their own statement, and there
                 is nothing on this screen for them to delete. --}}
            <x-table.bulk-bar
                class="mb-5"
                :count="$this->selectedCount"
                :matching="$selectMatching"
                subject="transactions"
            />

            <flux:table :paginate="$this->transactions">
                <x-table.columns
                    :columns="$this->tableColumnList"
                    :sort="$sortColumn"
                    :direction="$sortDirection"
                    selectable
                />

                <x-table.rows :columns="$this->tableColumnList">
                    @forelse ($this->transactions as $item)
                        <flux:table.row wire:key="txn-{{ $item->id }}">
                            <x-table.select :id="$item->id" />

                            <x-table.cell column="reference">
                                <p class="font-mono text-xs">{{ $item->reference }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->createdDatetimeHuman() }}</p>
                            </x-table.cell>

                            <x-table.cell column="description">
                                <p class="text-slate-950 dark:text-white">{{ $item->description }}</p>
                                <flux:badge size="sm" inset="top bottom">{{ $item->transaction_group->label() }}</flux:badge>
                            </x-table.cell>

                            <x-table.cell column="amount">
                                <span @class([
                                    'font-medium',
                                    'text-red-600 dark:text-red-400' => $item->transaction_type->isDebit(),
                                    'text-emerald-600 dark:text-emerald-400' => ! $item->transaction_type->isDebit(),
                                ])>
                                    {{ $item->signedAmount() }}
                                </span>
                                @if ($item->charges->isNotEmpty())
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        incl. {!! kMoneyFormat($item->totalCharges()) !!} charges
                                    </p>
                                @endif
                            </x-table.cell>

                            <x-table.cell column="balance">
                                {!! $item->balance ? kMoneyFormat($item->balance->balance_after) : '—' !!}
                            </x-table.cell>

                            <x-table.cell column="status">
                                <x-util.status :status="$item->status" />
                            </x-table.cell>

                            <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>
                        </flux:table.row>
                    @empty
                        <x-table.empty
                            :columns="$this->tableColumnList"
                            selectable
                            label="No transactions"
                            icon="receipt-percent"
                            text="Nothing matches the current filters."
                        />
                    @endforelse

                    <x-table.summary
                        :columns="$this->tableColumnList"
                        :summary="$this->tableSummary"
                        selectable
                    />
                </x-table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
