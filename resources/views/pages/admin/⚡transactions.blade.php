<?php

use App\Enums\GateAccessEnum;
use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Services\TrendService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $group = '';

    /** The transaction being settled, held while the dialog asks. */
    public ?int $settleId = null;

    public ?int $settleStatus = null;

    public string $settleNote = '';

    // Manual adjustment
    public ?int $adjust_user_id = null;

    public string $adjust_amount = '';

    public string $adjust_reason = '';

    public bool $adjust_credit = true;

    public function mount(): void
    {
        kSetSiteTitle('transactions');
        $this->setPageGate('transactions');
    }

    /**
     * The ledger as a table: which columns it has, what each one is good for, and
     * which of them a total belongs under.
     */
    protected function tableColumns(): array
    {
        return [
            // The reference is what identifies the row, so it stays whatever else is
            // put away.
            'reference' => $this->columnMaker('Reference', locked: true, sortable: true),
            'user' => $this->columnMaker('Account'),
            'amount' => $this->columnMaker('Amount', locked: true, sortable: true, summary: 'sum', money: true),
            'transaction_group' => $this->columnMaker('Group', sortable: true),
            'via' => $this->columnMaker('Via', sortable: true),
            'status' => $this->columnMaker('Status', sortable: true),
            'created_at' => $this->columnMaker('Date', sortable: true),
        ];
    }

    /**
     * The question the screen is asking, without its ordering. The bulk bar, the
     * totals and the export all ask it again, so the filters have to live here rather
     * than in the computed that paginates it.
     */
    protected function tableQuery(): Builder
    {
        $query = Transaction::query()
            ->with(['user', 'balance', 'gateway'])
            ->when($this->search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $group) => $group
                    ->searchMacro(['reference', 'description'], $this->search)
                    ->orWhereHas('user', fn (Builder $user) => $user->searchMacro(['name', 'email'], $this->search))))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', (int) $this->status))
            ->when($this->group !== '', fn (Builder $query) => $query->where('transaction_group', $this->group));

        return $this->applyDateRange($query);
    }

    protected function tableSubject(): string
    {
        return 'transactions';
    }

    /**
     * The filters, for the chips that take them off again. The group has no select
     * of its own — it arrives from a link on the dashboard — and the chip is what
     * says so on the screen it lands on.
     */
    protected function tableFilters(): array
    {
        return [
            'search' => $this->filterMaker('Search'),
            'status' => $this->filterMaker('Status', StatusTransaction::forSelect()),
            'group' => $this->filterMaker('Type', TransactionGroupEnum::forSelect()),
        ];
    }

    protected function tableDateLabel(): string
    {
        return 'Dated';
    }

    /**
     * One cell on the way into a file. The columns that are a relationship or a
     * formatted amount cannot be read straight off the model, and an export of
     * "App\Models\User" helps nobody.
     */
    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'user' => $item->user?->name,
            'amount' => $item->signedAmount(),
            'created_at' => $item->createdDatetimeHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->transactions, $this->metrics);
    }

    #[Computed]
    public function transactions()
    {
        // The chosen sort first, falling back to the ledger's own order — newest at
        // the top, and the id to break a tie between two rows written in the same
        // second.
        return $this->applySort($this->tableQuery(), 'created_at')
            ->orderByDesc('id')
            ->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, Model>
     */
    protected function tableRows(): iterable
    {
        return $this->transactions;
    }

    /**
     * @return array<int, array{label: string, value: string, icon: string, tone: string}>
     */
    #[Computed]
    public function metrics(): array
    {
        $pending = Transaction::query()->pending()->count();

        // Raw sums come back in minor units, so they are divided exactly once.
        $confirmedIn = (int) Transaction::query()
            ->confirmed()
            ->ofGroup(TransactionGroupEnum::DEPOSIT)
            ->sum('amount') / 100;

        return [
            $this->metricMaker(
                'Awaiting review',
                $pending,
                'clock',
                tone: $pending > 0 ? 'amber' : 'slate',
            ),
            $this->metricMaker(
                'Deposits confirmed',
                kMoneyFormat($confirmedIn, decodeHtml: true),
                'banknotes',
                tone: 'emerald',
                trend: $this->ledgerTrends['deposits'],
            ),
            $this->metricMaker(
                'All transactions',
                Transaction::query()->count(),
                'receipt-percent',
                tone: 'sky',
                trend: $this->ledgerTrends['all'],
            ),
        ];
    }

    /**
     * The last six months of the ledger, a month at a time, for the sparklines under
     * the figures. A total says where the ledger stands; the line says whether it got
     * there steadily or in one week.
     *
     * Both series come off one grouped read, and every month in the window is present
     * whether anything moved in it or not — see TrendService.
     *
     * @return array<string, array<int, object>>
     */
    #[Computed]
    public function ledgerTrends(): array
    {
        return app(TrendService::class)->trends(
            Transaction::query(),
            splitBy: ['transaction_group', 'status'],
            series: [
                'all' => [],

                // Money confirmed in. The stored amount is minor units, so the
                // series is divided exactly once, here.
                'deposits' => [
                    'match' => [
                        'transaction_group' => TransactionGroupEnum::DEPOSIT,
                        'status' => StatusTransaction::CONFIRMED,
                    ],
                    'sum' => 'amount',
                    'divideBy' => 100,
                ],
            ],
        );
    }

    public function updatedSearch(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // SETTLING

    public function confirmSettle(int $transactionId, int $status): void
    {
        $this->checkGate();

        $this->settleId = $transactionId;
        $this->settleStatus = $status;
        $this->settleNote = '';

        Flux::modal('settleModal')->show();
    }

    public function settle(): bool
    {
        // The menu row is hidden, which stops nobody who can open a console.
        $this->checkGate();

        $transaction = Transaction::query()->whereKey($this->settleId)->first();

        abort_unless((bool) $transaction && $this->settleStatus !== null, 404);

        $error = app(TransactionService::class)->settle(
            $transaction,
            StatusTransaction::from($this->settleStatus),
            $this->settleNote ?: null,
        );

        Flux::modal('settleModal')->close();
        $this->reset('settleId', 'settleStatus', 'settleNote');
        unset($this->transactions, $this->metrics, $this->tableSummary);

        $this->respondError($error, $error !== null);

        return $this->respondSuccess('The transaction has been settled.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // MANUAL ADJUSTMENT

    public function adjust(): bool
    {
        // An adjustment writes a new row in the ledger, so it asks for CREATE rather
        // than for the MODIFY that settling an existing one needs.
        $this->checkGate(GateAccessEnum::CREATE);

        $this->validate([
            'adjust_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'adjust_amount' => ['required', 'numeric', 'min:0.01'],
            'adjust_reason' => ['required', 'string', 'max:255'],
            'adjust_credit' => ['boolean'],
        ]);

        $user = User::query()->findOrFail($this->adjust_user_id);

        app(TransactionService::class)->adjust(
            $user,
            (float) $this->adjust_amount,
            $this->adjust_reason,
            $this->adjust_credit,
        );

        Flux::modal('adjustModal')->close();
        $this->reset('adjust_user_id', 'adjust_amount', 'adjust_reason', 'adjust_credit');
        unset($this->transactions, $this->metrics, $this->tableSummary);

        return $this->respondSuccess('The adjustment has been recorded.');
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Transaction metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card :metric="$metric" />
        @endforeach
    </section>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Transactions</flux:heading>
                <flux:text class="mt-1">The whole ledger, and the queue waiting on a decision.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Reference, description, name or email"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="status" class="sm:min-w-40">
                    <flux:select.option value="">All statuses</flux:select.option>
                    @foreach (StatusTransaction::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <x-dashboard.gate.button
                    :gate="$pageGate"
                    :level="$gateCreate"
                    variant="primary"
                    icon="plus"
                    x-on:click="$flux.modal('adjustModal').show()"
                >
                    Adjustment
                </x-dashboard.gate.button>
            </div>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            {{-- A ledger is read by period more often than by anything else, so the
                 range sits beside the filters rather than behind a menu. --}}
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

        <x-table.active-filters :filters="$this->tableActiveFilters" />

        <x-table.bulk-bar
            :count="$this->selectedCount"
            :total="$this->tableTotalCount"
            :matching="$selectMatching"
            :columns="$this->tableExportOptions"
            subject="transactions"
        />

        <flux:table :paginate="$this->transactions">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                selectable
                actions
                actions-label=""
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->transactions as $item)
                    <flux:table.row wire:key="txn-{{ $item->id }}">
                        <x-table.select :id="$item->id" />

                        <x-table.cell column="reference">
                            <p class="font-mono text-xs">{{ $item->reference }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->description }}</p>
                        </x-table.cell>

                        {{-- The account is the one thing on this row worth opening,
                             so the cell itself is the link rather than an eye button
                             in the actions column. --}}
                        <x-table.cell column="user" :href="$item->user ? route('admin.user', $item->user) : null">
                            <p class="font-medium text-slate-950 dark:text-white">{{ $item->user?->name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->user?->email }}</p>
                        </x-table.cell>

                        <x-table.cell column="amount">
                            <x-transaction.amount :transaction="$item" />
                        </x-table.cell>

                        <x-table.cell column="transaction_group">
                            <x-util.e-badge :enum="$item->transaction_group" />
                        </x-table.cell>

                        <x-table.cell column="via">
                            <flux:badge size="sm" inset="top bottom">{{ $item->via->label() }}</flux:badge>
                        </x-table.cell>

                        <x-table.cell column="status">
                            <x-util.e-badge :enum="$item->status" />
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdDatetimeHuman() }}</x-table.cell>

                        <x-table.cell>
                            @if ($item->isSettled())
                                <flux:text size="sm" class="text-slate-400">Settled</flux:text>
                            @elseif (kGateAction($pageGate, $gateModify))
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <x-dashboard.gate.menu-item
                                            :gate="$pageGate"
                                            :level="$gateModify"
                                            icon="check"
                                            wire:click="confirmSettle({{ $item->id }}, {{ StatusTransaction::CONFIRMED->value }})"
                                        >
                                            Confirm
                                        </x-dashboard.gate.menu-item>
                                        <x-dashboard.gate.menu-item
                                            :gate="$pageGate"
                                            :level="$gateModify"
                                            icon="x-mark"
                                            variant="danger"
                                            wire:click="confirmSettle({{ $item->id }}, {{ StatusTransaction::REJECTED->value }})"
                                        >
                                            Reject
                                        </x-dashboard.gate.menu-item>
                                    </flux:menu>
                                </flux:dropdown>
                            @else
                                {{-- A read-only account sees the state, not an empty menu. --}}
                                <flux:text size="sm" class="text-slate-400">Pending</flux:text>
                            @endif
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        selectable
                        actions
                        label="No transactions"
                        icon="receipt-percent"
                        text="Nothing in the ledger matches the current filters."
                    />
                @endforelse

                <x-table.summary
                    :columns="$this->tableColumnList"
                    :summary="$this->tableSummary"
                    selectable
                    actions
                />
            </x-table.rows>
        </flux:table>
    </flux:card>

    {{-- Settle --}}
    <flux:modal name="settleModal" class="max-w-md">
        <form wire:submit="settle" class="space-y-4">
            <flux:heading size="lg">Settle this transaction</flux:heading>

            <flux:text>
                This is final. A settled transaction cannot be settled again, and confirming
                one moves the account's balance.
            </flux:text>

            <flux:textarea wire:model="settleNote" label="Note" rows="2" description="Recorded in the activity log." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Settle</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Manual adjustment --}}
    <flux:modal name="adjustModal" class="max-w-md">
        <form wire:submit="adjust" class="space-y-4">
            <flux:heading size="lg">Manual adjustment</flux:heading>

            <flux:text>
                Moves money by hand and records who did it and why. Use it to correct a
                mistake, not as a routine way to pay somebody.
            </flux:text>

            <flux:input type="number" wire:model="adjust_user_id" label="Account id" />
            <flux:input type="number" step="0.01" wire:model="adjust_amount" label="Amount" />
            <flux:input wire:model="adjust_reason" label="Reason" placeholder="Refund for duplicate charge" />
            <flux:switch wire:model="adjust_credit" label="Credit the account" description="Turn off to debit instead." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Record it</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
