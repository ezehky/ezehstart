<?php

use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithFormResponseMessage, WithPagination;

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
    }

    #[Computed]
    public function transactions()
    {
        return Transaction::query()
            ->with(['user', 'balance', 'gateway'])
            ->when($this->search, fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('reference', 'like', '%'.$this->search.'%')
                    ->orWhereHas('user', fn (Builder $userQuery) => $userQuery
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%'))))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', (int) $this->status))
            ->when($this->group !== '', fn (Builder $query) => $query->where('transaction_group', $this->group))
            ->newestFirst()
            ->paginate(20);
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
            [
                'label' => 'Awaiting review',
                'value' => number_format($pending),
                'icon' => 'clock',
                'tone' => $pending > 0 ? 'amber' : 'slate',
            ],
            [
                'label' => 'Deposits confirmed',
                'value' => kMoneyFormat($confirmedIn, decodeHtml: true),
                'icon' => 'banknotes',
                'tone' => 'emerald',
            ],
            [
                'label' => 'All transactions',
                'value' => number_format(Transaction::query()->count()),
                'icon' => 'receipt-percent',
                'tone' => 'sky',
            ],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // SETTLING

    public function confirmSettle(int $transactionId, int $status): void
    {
        $this->settleId = $transactionId;
        $this->settleStatus = $status;
        $this->settleNote = '';

        Flux::modal('settleModal')->show();
    }

    public function settle(): bool
    {
        $transaction = Transaction::query()->whereKey($this->settleId)->first();

        abort_unless((bool) $transaction && $this->settleStatus !== null, 404);

        $error = app(TransactionService::class)->settle(
            $transaction,
            StatusTransaction::from($this->settleStatus),
            $this->settleNote ?: null,
        );

        Flux::modal('settleModal')->close();
        $this->reset('settleId', 'settleStatus', 'settleNote');
        unset($this->transactions, $this->metrics);

        $this->respondError($error, $error !== null);

        return $this->respondSuccess('The transaction has been settled.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // MANUAL ADJUSTMENT

    public function adjust(): bool
    {
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
        unset($this->transactions, $this->metrics);

        return $this->respondSuccess('The adjustment has been recorded.');
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Transaction metrics">
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
                <flux:heading level="2" size="lg">Transactions</flux:heading>
                <flux:text class="mt-1">The whole ledger, and the queue waiting on a decision.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Reference, name or email"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="status" class="sm:min-w-40">
                    <flux:select.option value="">All statuses</flux:select.option>
                    @foreach (StatusTransaction::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button variant="primary" icon="plus" x-on:click="$flux.modal('adjustModal').show()">
                    Adjustment
                </flux:button>
            </div>
        </div>

        @if ($this->transactions->isEmpty())
            <x-dashboard.workspace-no-record
                icon="receipt-percent"
                label="No transactions"
                text="Nothing has moved through the ledger yet."
            />
        @else
            <flux:table :paginate="$this->transactions">
                <flux:table.columns>
                    <flux:table.column>Reference</flux:table.column>
                    <flux:table.column>Account</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Via</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->transactions as $item)
                        <flux:table.row wire:key="txn-{{ $item->id }}">
                            <flux:table.cell>
                                <p class="font-mono text-xs">{{ $item->reference }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->createdDatetimeHuman() }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <p class="font-medium text-slate-950 dark:text-white">{{ $item->user?->name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->description }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span @class([
                                    'font-medium',
                                    'text-red-600 dark:text-red-400' => $item->transaction_type->isDebit(),
                                    'text-emerald-600 dark:text-emerald-400' => ! $item->transaction_type->isDebit(),
                                ])>
                                    {{ $item->signedAmount() }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" inset="top bottom">{{ $item->via->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-status :status="$item->status" />
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (! $item->isSettled())
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item
                                                icon="check"
                                                wire:click="confirmSettle({{ $item->id }}, {{ StatusTransaction::CONFIRMED->value }})"
                                            >
                                                Confirm
                                            </flux:menu.item>
                                            <flux:menu.item
                                                icon="x-mark"
                                                variant="danger"
                                                wire:click="confirmSettle({{ $item->id }}, {{ StatusTransaction::REJECTED->value }})"
                                            >
                                                Reject
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    <flux:text size="sm" class="text-slate-400">Settled</flux:text>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
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
