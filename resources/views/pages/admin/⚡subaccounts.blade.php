<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusDefault;
use App\Models\PaymentSubaccount;
use App\Rules\EmailRule;
use App\Services\ActivityLogService;
use App\Services\Payment\PaymentSubaccountService;
use App\Traits\WithGateProps;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithGateProps, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $vendorFilter = '';

    // Subaccount form
    public ?int $editingId = null;

    public string $vendor = '';

    public string $business_name = '';

    public string $description = '';

    public string $bank_code = '';

    public string $account_number = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public $platform_percentage = 0;

    public bool $active = true;

    /** The subaccount the delete dialog is about. */
    public ?int $targetId = null;

    public function mount(): void
    {
        kSetSiteTitle('finance', 'subaccounts');
        $this->setPageGate('finance.subaccounts');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedVendorFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Bank codes are the vendor's own, so a bank picked for one vendor means
     * nothing to the next.
     */
    public function updatedVendor(): void
    {
        // The select is disabled on an edit; a request that changes it anyway
        // gets the subaccount's own provider back.
        if ($this->editingId) {
            $this->vendor = (string) PaymentSubaccount::query()->whereKey($this->editingId)->first()?->vendor->value;

            return;
        }

        $this->bank_code = '';
        $this->resetValidation(['vendor', 'bank_code']);
        unset($this->bankList, $this->banks, $this->bankError, $this->needsEmail, $this->needsPhone);
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    #[Computed]
    public function subaccounts()
    {
        return PaymentSubaccount::query()
            ->withCount('gateways')
            ->when($this->vendorFilter !== '', fn (Builder $query) => $query->where('vendor', $this->vendorFilter))
            ->when($this->search !== '', fn (Builder $query) => $query->searchMacro(['business_name', 'subaccount_code', 'account_number', 'account_name'], $this->search))
            ->alphabetical()
            ->paginate(20);
    }

    /**
     * Every provider the kit can create a subaccount on, and whether this
     * install has its keys. One without keys is shown but cannot be picked.
     *
     * @return array<int, array{vendor: PaymentVendorEnum, configured: bool}>
     */
    #[Computed]
    public function vendorOptions(): array
    {
        return collect(PaymentVendorEnum::subaccountVendors())
            ->map(fn (PaymentVendorEnum $vendor) => ['vendor' => $vendor, 'configured' => $vendor->isConfigured()])
            ->all();
    }

    /**
     * The provider a new subaccount starts on — the first with keys, so the
     * bank list is there straight away. Null when none has them.
     */
    #[Computed]
    public function firstConfiguredVendor(): ?string
    {
        return collect($this->vendorOptions)->firstWhere('configured', true)['vendor']->value ?? null;
    }

    #[Computed]
    public function vendorNames(): string
    {
        return collect($this->vendorOptions)->map(fn (array $row) => $row['vendor']->label())->join(', ', ' and ');
    }

    /**
     * The vendor's banks for the picked provider. Empty until one is picked,
     * or when the vendor could not be asked — bankError then says why.
     *
     * @return array<int, array{code: string, name: string}>
     */
    #[Computed]
    public function banks(): array
    {
        return \is_array($this->bankList) ? $this->bankList : [];
    }

    #[Computed]
    public function bankError(): ?string
    {
        return \is_string($this->bankList) ? $this->bankList : null;
    }

    /**
     * The one call behind both of the above.
     *
     * @return string|array<int, array{code: string, name: string}>
     */
    #[Computed]
    public function bankList(): string|array
    {
        $vendor = PaymentVendorEnum::tryFrom($this->vendor);

        return $vendor ? app(PaymentSubaccountService::class)->banks($vendor) : [];
    }

    /**
     * What each provider insists on beyond the bank account, for the form's
     * labels and the rules alike.
     */
    #[Computed]
    public function needsEmail(): bool
    {
        return PaymentVendorEnum::tryFrom($this->vendor)?->isMonnify() ?? false;
    }

    #[Computed]
    public function needsPhone(): bool
    {
        return PaymentVendorEnum::tryFrom($this->vendor)?->isFlutterwave() ?? false;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // SUBACCOUNTS

    protected function rules(): array
    {
        $vendors = collect($this->vendorOptions)
            ->filter(fn (array $row) => $row['configured'])
            ->map(fn (array $row) => $row['vendor']->value)
            ->all();

        return [
            'vendor' => ['required', Rule::in($vendors)],
            'business_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:250'],
            'bank_code' => ['required', Rule::in(collect($this->banks)->pluck('code')->all())],
            'account_number' => ['required', 'digits_between:6,20'],
            // EmailRule is not an implicit rule, so Laravel never runs it on a
            // blank value — the presence check has to be its own rule.
            'contact_email' => [$this->needsEmail ? 'required' : 'nullable', new EmailRule(required: $this->needsEmail, max: 190)],
            'contact_phone' => [$this->needsPhone ? 'required' : 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'platform_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'bank_code' => 'bank',
            'contact_email' => 'contact email',
            'contact_phone' => 'contact phone',
            'platform_percentage' => 'site share',
        ];
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetValidation();
        $this->reset('editingId', 'vendor', 'business_name', 'description', 'bank_code', 'account_number', 'contact_email', 'contact_phone', 'platform_percentage');
        $this->active = true;

        $this->vendor = (string) $this->firstConfiguredVendor;
        unset($this->bankList, $this->banks, $this->bankError, $this->needsEmail, $this->needsPhone);

        Flux::modal('subaccountModal')->show();
    }

    public function edit(int $subaccountId): void
    {
        $this->checkGate();

        $subaccount = PaymentSubaccount::query()->findOrFail($subaccountId);

        $this->resetValidation();
        $this->editingId = $subaccount->id;
        $this->vendor = $subaccount->vendor->value;
        $this->business_name = $subaccount->business_name;
        $this->description = (string) $subaccount->description;
        $this->bank_code = $subaccount->bank_code;
        $this->account_number = $subaccount->account_number;
        $this->contact_email = (string) $subaccount->contact_email;
        $this->contact_phone = (string) $subaccount->contact_phone;
        $this->platform_percentage = (float) $subaccount->platform_percentage;
        $this->active = $subaccount->status->isActive();
        unset($this->bankList, $this->banks, $this->bankError, $this->needsEmail, $this->needsPhone);

        Flux::modal('subaccountModal')->show();
    }

    /**
     * Created or changed on the provider first; the row is saved only once
     * the provider accepts it. A subaccount stays on the provider it was made
     * on — moving one is a new subaccount.
     */
    public function save(): bool
    {
        $this->checkGate($this->editingId === null ? GateAccessEnum::CREATE : GateAccessEnum::MODIFY);

        $subaccount = $this->editingId ? PaymentSubaccount::query()->findOrFail($this->editingId) : new PaymentSubaccount;
        $isNew = ! $subaccount->exists;

        if (! $isNew) {
            $this->vendor = $subaccount->vendor->value;
        }

        $this->validate();

        $subaccount->vendor = PaymentVendorEnum::from($this->vendor);
        $subaccount->business_name = $this->business_name;
        $subaccount->description = $this->description ?: null;
        $subaccount->bank_code = $this->bank_code;
        $subaccount->bank_name = collect($this->banks)->firstWhere('code', $this->bank_code)['name'] ?? $subaccount->bank_name;
        $subaccount->account_number = $this->account_number;
        $subaccount->contact_email = $this->contact_email ?: null;
        $subaccount->contact_phone = $this->contact_phone ?: null;
        $subaccount->platform_percentage = round((float) $this->platform_percentage, 2);
        $subaccount->status = $this->active ? StatusDefault::ACTIVE : StatusDefault::INACTIVE;

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($subaccount);

        $result = app(PaymentSubaccountService::class)->save($subaccount);

        $this->respondError($result, \is_string($result));

        $serviceInstance->logActivity(
            $isNew ? ActivityActionEnum::CREATE : ActivityActionEnum::UPDATE,
            " payment subaccount: {$result->label()}",
            $affectedColumns,
            model: $result,
        );

        Flux::modal('subaccountModal')->close();
        unset($this->subaccounts);

        return $this->respondSuccess($isNew
            ? "Subaccount created on {$result->vendor->label()}."
            : 'Subaccount updated.');
    }

    public function confirmDelete(int $subaccountId): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to subaccounts.');

        $this->targetId = $subaccountId;

        Flux::modal('deleteSubaccountModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $subaccount = PaymentSubaccount::query()->whereKey($this->targetId)->first();

        abort_unless((bool) $subaccount, 404);

        $this->respondError(
            'Payments have been split with this subaccount, so it stays on file. Switch it off instead.',
            app(PaymentSubaccountService::class)->isUsed($subaccount),
        );

        $subaccount->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::DELETE, " payment subaccount: {$subaccount->label()}", model: $subaccount);

        $this->reset('targetId');
        unset($this->subaccounts);

        return $this->respondSuccess('Subaccount removed from this site.');
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Subaccounts</flux:heading>
                <flux:text class="mt-1">
                    Payees a payment can be split with. The provider pays each one its share
                    straight into its own bank account and the rest to this site.
                </flux:text>
            </div>

            <x-dashboard.gate.button :gate="$pageGate" :level="$gateCreate" variant="primary" icon="plus" wire:click="create">
                New subaccount
            </x-dashboard.gate.button>
        </div>

        @unless ($this->firstConfiguredVendor)
            <flux:callout color="sky" icon="information-circle">
                <flux:callout.text>
                    No provider that splits payments has its keys set. {{ $this->vendorNames }} can;
                    set one in the environment and it is offered here.
                </flux:callout.text>
            </flux:callout>
        @endunless

        <div class="flex flex-col gap-3 sm:flex-row">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search by name, code or account" class="sm:max-w-sm" />

            <flux:select wire:model.live="vendorFilter" class="sm:max-w-48">
                <flux:select.option value="">All providers</flux:select.option>
                @foreach ($this->vendorOptions as $row)
                    <flux:select.option value="{{ $row['vendor']->value }}">{{ $row['vendor']->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->subaccounts->isEmpty())
            <x-dashboard.workspace-no-record
                icon="building-library"
                label="No subaccounts yet"
                text="Create one, and any checkout that names it is split with that payee."
            />
        @else
            <flux:table :paginate="$this->subaccounts">
                <flux:table.columns>
                    <flux:table.column>Payee</flux:table.column>
                    <flux:table.column>Settles to</flux:table.column>
                    <flux:table.column>Split</flux:table.column>
                    <flux:table.column>Payments</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->subaccounts as $subaccount)
                        <flux:table.row wire:key="subaccount-{{ $subaccount->id }}">
                            <flux:table.cell>
                                <p class="font-medium text-slate-950 dark:text-white">{{ $subaccount->business_name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $subaccount->vendor->label() }} · <span class="font-mono">{{ $subaccount->subaccount_code }}</span>
                                </p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <p>{{ $subaccount->account_name ?? '—' }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $subaccount->bank_name ?? $subaccount->bank_code }} · {{ $subaccount->maskedAccountNumber() }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <p>Payee {{ kMoney($subaccount->subaccountPercentage()) }}%</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Site {{ kMoney($subaccount->platform_percentage) }}%</p>
                            </flux:table.cell>
                            <flux:table.cell>{{ $subaccount->gateways_count }}</flux:table.cell>
                            <flux:table.cell><x-util.e-badge :enum="$subaccount->status" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <x-dashboard.gate.menu-item :gate="$pageGate" :level="$gateModify" icon="pencil-square" wire:click="edit({{ $subaccount->id }})">
                                            Edit
                                        </x-dashboard.gate.menu-item>
                                        @if ($subaccount->gateways_count === 0)
                                            <x-dashboard.gate.menu-item :gate="$pageGate" :level="$gateFull" icon="trash" variant="danger" wire:click="confirmDelete({{ $subaccount->id }})">
                                                Delete
                                            </x-dashboard.gate.menu-item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal name="subaccountModal" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit subaccount' : 'New subaccount' }}</flux:heading>
                <flux:text class="mt-1">The provider checks the bank account and names its holder when it accepts it.</flux:text>
            </div>

            <div class="space-y-4">
                <flux:select wire:model.live="vendor" label="Provider" placeholder="Pick a provider" :disabled="(bool) $editingId" :description="$editingId ? 'A subaccount stays on the provider it was made on.' : 'Only providers that can split a payment are listed.'">
                    @foreach ($this->vendorOptions as $row)
                        <flux:select.option value="{{ $row['vendor']->value }}" :disabled="! $row['configured']">
                            {{ $row['vendor']->label() }}{{ $row['configured'] ? '' : ' — keys not set' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="business_name" label="Business name" placeholder="Ada's Bakery" />
                <flux:input wire:model="description" label="Description" placeholder="Vendor on the marketplace since 2026" />
            </div>

            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <flux:heading>Bank account</flux:heading>

                @if ($this->bankError)
                    <flux:callout color="red" icon="exclamation-triangle">
                        <flux:callout.text>The bank list could not be fetched: {{ $this->bankError }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div wire:key="banks-{{ $vendor }}" class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="bank_code" label="Bank" placeholder="Pick a bank">
                        @foreach ($this->banks as $bank)
                            <flux:select.option value="{{ $bank['code'] }}">{{ $bank['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="account_number" label="Account number" placeholder="0123456789" inputmode="numeric" />
                </div>
            </div>

            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <flux:heading>Split</flux:heading>

                <flux:input
                    wire:model="platform_percentage"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    label="Site share"
                    placeholder="e.g. 10"
                    suffix="%"
                    description="What this site keeps of each payment split with the subaccount. The payee is paid the rest. The provider's own fee comes out of the site's share."
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="contact_email" type="email" :label="$this->needsEmail ? 'Contact email' : 'Contact email (optional)'" placeholder="accounts@adasbakery.com" />
                    <flux:input wire:model="contact_phone" type="tel" :label="$this->needsPhone ? 'Contact phone' : 'Contact phone (optional)'" placeholder="+234 803 000 0000" />
                </div>
            </div>

            <flux:switch wire:model="active" label="Can be paid" description="Switched off, checkouts naming it are refused. Payments already split with it are untouched." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId ? 'Save subaccount' : 'Create subaccount' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteSubaccountModal"
        title="Delete this subaccount?"
        icon="trash"
        confirm="Delete subaccount"
        confirm-icon="trash"
        wire:click="delete"
    >
        It is removed from this site only — the provider keeps its copy, which can be switched
        off from the provider's dashboard. One that payments were split with stays on file.
    </x-dashboard.confirm-modal>
</div>
