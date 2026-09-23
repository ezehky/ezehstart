<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Models\Currency;
use App\Services\ActivityLogService;
use App\Services\CurrencyService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithDataTable;

    public ?Currency $currency = null;

    public string $name = '';

    public string $code = '';

    public string $symbol = '';

    public ?float $rate = 1;

    public bool $status = true;

    /** The currency queued for deletion or for becoming the default, held while the dialog asks. */
    public ?int $pendingId = null;

    public function mount(): void
    {
        kSetSiteTitle('config', 'currencies');
        $this->setPageGate('config.currencies');
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'name' => $this->columnMaker('Currency', locked: true, sortable: true),
            'code' => $this->columnMaker('Code', sortable: true),
            'rate' => $this->columnMaker('Rate', sortable: true),
            'users_count' => $this->columnMaker('Users', exportable: false),
            'status' => $this->columnMaker('Status', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        return Currency::query()->withCount('users');
    }

    protected function tableSubject(): string
    {
        return 'currencies';
    }

    /**
     * Every currency is on the page at once — there are never enough of them to
     * page through — so the header checkbox takes all of them.
     *
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->currencies;
    }

    /**
     * @return Collection<int, Currency>
     */
    #[Computed]
    public function currencies(): Collection
    {
        // The default first, because every other row's rate is read against it.
        return $this->applySort($this->tableQuery()->orderByDesc('is_default'), 'name', 'asc')->get();
    }

    #[Computed]
    public function defaultCurrency(): ?Currency
    {
        return $this->currencies->first(fn (Currency $currency) => $currency->isDefault());
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetForm();

        Flux::modal('currencyModal')->show();
    }

    public function edit(Currency $currency): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->resetForm();

        $this->currency = $currency;
        $this->name = $currency->name;
        $this->code = $currency->code;
        $this->symbol = $currency->symbol;
        $this->rate = $currency->rate;
        $this->status = $currency->status->isActive();

        Flux::modal('currencyModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'size:3',
                'alpha',
                Rule::unique(Currency::class, 'code')->ignore($this->currency?->id),
            ],
            'symbol' => ['required', 'string', 'max:20'],
            // The default is 1 by definition and is not edited here — see save().
            'rate' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'status' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['code' => 'ISO code'];
    }

    public function save(): bool
    {
        $this->checkGate($this->currency ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);

        $this->validate();

        $action = ActivityActionEnum::CURRENCY_UPDATE;

        if (! $this->currency) {
            $this->currency = Currency::make(['is_default' => StatusYes::NO]);
            $action = ActivityActionEnum::CURRENCY_CREATE;
        }

        $isDefault = $this->currency->isDefault();

        $this->currency->name = $this->name;
        $this->currency->code = strtoupper($this->code);
        $this->currency->symbol = $this->symbol;

        // Every other rate is quoted against the default, so its own rate is 1 and
        // stays 1, and it cannot be switched off while the ledger is read in it.
        // Changing which currency is the default is its own action with its own
        // warning, not a side effect of editing a row.
        $this->currency->rate = $isDefault ? 1 : (float) $this->rate;
        $this->currency->status = $isDefault ? StatusDefault::ACTIVE : StatusDefault::tryFrom((int) $this->status);

        $this->respondPrimary(if: $this->currency->isClean());

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->currency);

        $this->currency->save();

        app(CurrencyService::class)->flush();

        $serviceInstance->logActivity(
            $action,
            " currency: {$this->currency->code}",
            $affectedColumns,
            model: $this->currency,
        );

        Flux::modal('currencyModal')->close();
        $this->resetForm();
        unset($this->currencies, $this->defaultCurrency);

        return $this->respondSuccess('The currency has been saved.');
    }

    public function confirmDefault(int $currencyId): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->pendingId = $currencyId;

        Flux::modal('defaultCurrencyModal')->show();
    }

    /**
     * Full access rather than modify: this changes what every stored amount on
     * the site is read as, which is a bigger decision than any single row.
     */
    public function makeDefault(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $currency = Currency::query()->whereKey($this->pendingId)->first();

        abort_unless((bool) $currency, 404);

        $this->respondPrimary('That is already the default currency.', if: $currency->isDefault());

        app(CurrencyService::class)->makeDefault($currency);

        Flux::modal('defaultCurrencyModal')->close();
        $this->reset('pendingId');
        unset($this->currencies, $this->defaultCurrency);

        return $this->respondSuccess("{$currency->code} is now the default currency. Every other rate has been rebased against it.");
    }

    public function confirmDelete(int $currencyId): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->pendingId = $currencyId;

        Flux::modal('deleteCurrencyModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $currency = Currency::query()->whereKey($this->pendingId)->first();

        abort_unless((bool) $currency, 404);

        $this->respondError(
            'The default currency cannot be deleted. Make another currency the default first.',
            if: $currency->isDefault(),
        );

        $description = " currency: {$currency->code}";

        // Accounts that had picked it fall back to the default, by the foreign
        // key's nullOnDelete — nobody is left pointing at a currency that is gone.
        $currency->delete();

        app(CurrencyService::class)->flush();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::CURRENCY_DELETE, $description);

        Flux::modal('deleteCurrencyModal')->close();
        $this->reset('pendingId');
        unset($this->currencies, $this->defaultCurrency);

        return $this->respondSuccess('The currency has been deleted.');
    }

    private function resetForm(): void
    {
        $this->reset('currency', 'name', 'code', 'symbol', 'rate', 'status');
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Currencies</flux:heading>
                <flux:text class="mt-1">
                    Money is stored in the default currency. The others are ways of reading it —
                    members pick one on their settings screen and every amount is converted for
                    them at these rates.
                </flux:text>
            </div>

            <x-dashboard.gate.button :gate="$pageGate" :level="$gateCreate" variant="primary" icon="plus" wire:click="create">
                New currency
            </x-dashboard.gate.button>
        </div>

        @if ($this->defaultCurrency)
            <flux:callout icon="banknotes" color="lime" class="text-sm">
                Rates are how many of each currency one {!! $this->defaultCurrency->symbol !!}1
                ({{ $this->defaultCurrency->code }}) buys.
            </flux:callout>
        @endif

        @if ($this->currencies->isEmpty())
            <x-dashboard.workspace-no-record
                icon="banknotes"
                label="No currencies yet"
                text="Run the currency seeder or add the first one by hand. Until then amounts are shown in naira."
            />
        @else
            <flux:table>
                <x-table.columns
                    :columns="$this->tableColumnList"
                    :sort="$sortColumn"
                    :direction="$sortDirection"
                    actions
                    actions-label=""
                />

                <x-table.rows :columns="$this->tableColumnList">
                    @foreach ($this->currencies as $item)
                        <flux:table.row wire:key="currency-{{ $item->id }}">
                            <x-table.cell column="name">
                                <div class="flex items-center gap-2">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-sm font-semibold dark:bg-slate-800">{!! $item->symbol !!}</span>
                                    <span class="font-medium text-slate-950 dark:text-white">{{ $item->name }}</span>
                                    @if ($item->isDefault())
                                        <flux:badge size="sm" color="lime" inset="top bottom">Default</flux:badge>
                                    @endif
                                </div>
                            </x-table.cell>

                            <x-table.cell column="code"><span class="font-mono text-xs">{{ $item->code }}</span></x-table.cell>
                            <x-table.cell column="rate"><span class="tabular-nums">{{ $item->rateText() }}</span></x-table.cell>
                            <x-table.cell column="users_count">{{ number_format($item->users_count) }}</x-table.cell>
                            <x-table.cell column="status"><x-util.e-badge :enum="$item->status" /></x-table.cell>

                            <x-table.cell class="flex justify-end gap-1">
                                @unless ($item->isDefault())
                                    <x-dashboard.gate.button
                                        :gate="$pageGate"
                                        :level="$gateFull"
                                        size="sm"
                                        variant="ghost"
                                        icon="star"
                                        tooltip="Make default"
                                        wire:click="confirmDefault({{ $item->id }})"
                                    />
                                @endunless
                                <x-dashboard.gate.button
                                    :gate="$pageGate"
                                    :level="$gateModify"
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil-square"
                                    wire:click="edit({{ $item->id }})"
                                />
                                @unless ($item->isDefault())
                                    <x-dashboard.gate.button
                                        :gate="$pageGate"
                                        :level="$gateFull"
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="confirmDelete({{ $item->id }})"
                                    />
                                @endunless
                            </x-table.cell>
                        </flux:table.row>
                    @endforeach
                </x-table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal name="currencyModal" class="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $currency ? 'Edit currency' : 'New currency' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" label="Name" placeholder="US Dollar" />
                <flux:input wire:model="code" label="ISO code" placeholder="USD" maxlength="3" class:input="uppercase" />
            </div>

            <flux:input
                wire:model="symbol"
                label="Symbol"
                placeholder="$"
                description="The glyph itself, or its HTML entity — &amp;#36; for a dollar sign."
            />

            @if ($currency?->isDefault())
                <flux:callout color="zinc" class="text-sm">
                    This is the default currency, so its rate is always 1 and it cannot be switched off.
                </flux:callout>
            @else
                <x-form.number-field
                    wire:model="rate"
                    label="Rate"
                    step="any"
                    min="0"
                    placeholder="e.g. 0.00065"
                    description:trailing="How many of this currency one unit of the default buys."
                />
                <flux:switch wire:model="status" label="Offered to users" description="Off hides it from the picker. Anybody who had chosen it reads amounts in the default instead." />
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="defaultCurrencyModal"
        title="Change the default currency?"
        icon="star"
        tone="amber"
        confirm="Make it the default"
        cancel="Keep the current one"
        wire:click="makeDefault"
    >
        Every rate is rebased so this currency becomes 1. Amounts already stored are
        <strong>not</strong> converted — they were written in the old default and will now be
        read in this one. Do this while setting a site up, not once money is on the books.
    </x-dashboard.confirm-modal>

    <x-dashboard.confirm-modal
        name="deleteCurrencyModal"
        title="Delete this currency?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        Anybody who reads amounts in it goes back to the default. To stop offering it for a
        while, switch it off instead.
    </x-dashboard.confirm-modal>
</div>
