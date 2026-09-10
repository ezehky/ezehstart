<?php

use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

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

    #[Computed]
    public function transactions()
    {
        return $this->user->transactions()
            ->with(['charges', 'balance'])
            ->when($this->group !== '', fn (Builder $query) => $query->where('transaction_group', $this->group))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', (int) $this->status))
            ->newestFirst()
            ->paginate(15);
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

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
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
            <flux:table :paginate="$this->transactions">
                <flux:table.columns>
                    <flux:table.column>Reference</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Balance after</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->transactions as $item)
                        <flux:table.row wire:key="txn-{{ $item->id }}">
                            <flux:table.cell>
                                <p class="font-mono text-xs">{{ $item->reference }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->createdDatetimeHuman() }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <p class="text-slate-950 dark:text-white">{{ $item->description }}</p>
                                <flux:badge size="sm" inset="top bottom">{{ $item->transaction_group->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
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
                            </flux:table.cell>
                            <flux:table.cell>
                                {!! $item->balance ? kMoneyFormat($item->balance->balance_after) : '—' !!}
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-status :status="$item->status" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
