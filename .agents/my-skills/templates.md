# templates.md

Every reusable template in one place. Copy, rename, fill in. Each links to the skill
that explains it.

---

## 1. Enum — status ([enums.md](enums.md))

```php
<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusInvoice: int
{
    use WithEnumHelpers;

    case DRAFT = 0;
    case ISSUED = 1;
    case PAID = 2;
    case VOID = 3;

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isIssued(): bool
    {
        return $this === self::ISSUED;
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    public function isVoid(): bool
    {
        return $this === self::VOID;
    }
}
```

Then add the colours to `config/_setups.php` → `status-color-map`.

## 2. Enum — vocabulary ([enums.md](enums.md))

```php
<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum InvoiceChannelEnum: string
{
    use WithEnumHelpers;

    case EMAIL = 'email';
    case POST = 'post';

    public function isEmail(): bool
    {
        return $this === self::EMAIL;
    }

    public function isPost(): bool
    {
        return $this === self::POST;
    }

    /**
     * The heading this group appears under in the admin.
     */
    public function defaultTitle(): string
    {
        return match ($this) {
            self::EMAIL => 'Emailed invoices',
            self::POST => 'Posted invoices',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EMAIL => 'envelope',
            self::POST => 'inbox-stack',
        };
    }
}
```

## 3. Migration ([migrations.md](migrations.md))

```php
<?php

use App\Enums\InvoiceChannelEnum;
use App\Enums\StatusInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 200)->unique();
            $table->string('title');

            $table->unsignedBigInteger('amount');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->text('notes')->nullable();

            $table->string('channel', 30)->default(InvoiceChannelEnum::EMAIL)->index();
            $table->tinyInteger('status')->default(StatusInvoice::DRAFT)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
```

## 4. Model ([models.md](models.md))

```php
<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InvoiceChannelEnum;
use App\Enums\StatusInvoice;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Invoice extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'channel' => InvoiceChannelEnum::class,
            'status' => StatusInvoice::class,
        ];
    }

    // Getters

    public function label(): string
    {
        return "Invoice {$this->reference}";
    }

    /**
     * A paid invoice is a settled record and must not be edited.
     */
    public function isSettled(): bool
    {
        return $this->status->isPaid();
    }

    public function allowUpdate(): bool
    {
        return ! $this->isSettled();
    }

    public function lockedReason(): string
    {
        return "This invoice is {$this->status->label()} and can no longer be updated.";
    }

    public function canDelete(): bool
    {
        return $this->lines()->count() === 0;
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusInvoice::ISSUED);
    }

    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }
}
```

## 5. Service ([services.md](services.md))

```php
<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One paragraph on why this service exists and what decision it owns.
 */
#[Singleton]
class InvoiceService
{
    // Getters

    /**
     * Invoices a user still owes on, oldest first.
     *
     * @return Collection<int, Invoice>
     */
    public function outstandingFor(User $user): Collection
    {
        return $this->outstandingQuery($user)->oldest()->get();
    }

    // Actions

    /**
     * Issue an invoice against a user's account.
     *
     * @return string|Invoice The invoice, or the reason it could not be issued.
     */
    public function issue(User $user, float $amount): string|Invoice
    {
        if ($amount <= 0) {
            return 'An invoice must be for more than zero.';
        }

        try {
            $invoice = DB::transaction(function () use ($user, $amount) {
                $user->lockForUpdate();

                return Invoice::query()->create([
                    'user_id' => $user->id,
                    'reference' => kReferenceId('INV-'),
                    'amount' => $amount,
                    'status' => StatusInvoice::ISSUED,
                    'issued_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            Log::channel('ezeh')->error('Error issuing invoice: '.$exception->getMessage(), [
                'exception' => $exception,
                'user_id' => $user->id,
            ]);

            return 'The invoice could not be issued. Please try again.';
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::INVOICE_CREATE,
            " invoice: {$invoice->reference}",
            model: $invoice,
        );

        return $invoice;
    }

    /**
     * Why this invoice cannot be voided, or null when it can.
     */
    public function voidBlockedReason(Invoice $invoice): ?string
    {
        if ($invoice->status->isPaid()) {
            return 'A paid invoice cannot be voided.';
        }

        return null;
    }

    // Tools

    private function outstandingQuery(User $user): Builder
    {
        return Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', StatusInvoice::ISSUED);
    }
}
```

## 6. Livewire CRUD page ([pages.md](pages.md) shape A)

```php
<?php

use App\Enums\ActivityActionEnum;
use App\Enums\InvoiceChannelEnum;
use App\Enums\StatusInvoice;
use App\Models\Invoice;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public ?Invoice $invoice = null;

    public string $title = '';

    public float $amount = 0;

    public InvoiceChannelEnum $channel = InvoiceChannelEnum::EMAIL;

    public ?string $notes = null;

    public bool $status = true;

    public array $channels;

    public function mount(): void
    {
        kSetSiteTitle('finance', 'invoices');
        $this->channels = InvoiceChannelEnum::forSelect();
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return Invoice::query()
            ->select('id', 'reference', 'title', 'amount', 'channel', 'status', 'updated_at')
            ->latest()
            ->get();
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('invoiceModal')->show();
    }

    public function edit(Invoice $invoice): void
    {
        $this->resetForm();

        $this->invoice = $invoice;
        $this->fill($invoice->only(['title', 'amount', 'channel', 'notes']));
        $this->status = $invoice->status->boolValue();

        Flux::modal('invoiceModal')->show();
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'channel' => ['required', Rule::enum(InvoiceChannelEnum::class)],
            'notes' => ['nullable', 'string'],
            'status' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        $action = ActivityActionEnum::INVOICE_UPDATE;

        if (! $this->invoice) {
            $this->invoice = Invoice::make();
            $this->invoice->reference = kReferenceId('INV-');
            $action = ActivityActionEnum::INVOICE_CREATE;
        }

        $this->invoice->fill([
            'title' => $this->title,
            'amount' => $this->amount,
            'channel' => $this->channel,
            'notes' => $this->notes,
            'status' => StatusInvoice::tryFrom((int) $this->status),
        ]);

        // Check if is clean (no changes) and return early
        $this->respondPrimary(if: $this->invoice->isClean());

        // ||||||||
        // Log Service
        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->invoice);
        // ||||||||

        $this->invoice->save();

        $serviceInstance->logActivity(
            $action,
            " invoice: {$this->invoice->reference}",
            $affectedColumns,
            model: $this->invoice,
        );

        Flux::modal('invoiceModal')->close();
        $this->resetForm();

        unset($this->invoices);

        return $this->respondSuccess('The invoice has been saved.');
    }

    public function delete(Invoice $invoice): bool
    {
        $description = " invoice: {$invoice->reference}";

        $invoice->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::INVOICE_DELETE, $description);

        unset($this->invoices);

        return $this->respondSuccess('The invoice has been deleted.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('invoice', 'title', 'amount', 'channel', 'notes', 'status');
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Invoices</flux:heading>
                <flux:text class="mt-1">Issue and track what customers owe.</flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create">Add invoice</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Reference</flux:table.column>
                <flux:table.column>Title</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column>Channel</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Updated</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->invoices as $item)
                    <flux:table.row wire:key="invoice-{{ $item->id }}">
                        <flux:table.cell class="font-medium">{{ $item->reference }}</flux:table.cell>
                        <flux:table.cell>{{ $item->title }}</flux:table.cell>
                        <flux:table.cell>{!! $item->amountMoney() !!}</flux:table.cell>
                        <flux:table.cell>{{ $item->channel->label() }}</flux:table.cell>
                        <flux:table.cell><x-util.e-badge :enum="$item->status" /></flux:table.cell>
                        <flux:table.cell>{{ $item->updatedAtHuman() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="right" align="start">
                                <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $item->id }})">
                                        Edit
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item
                                        icon="trash"
                                        variant="danger"
                                        wire:click="confirmDelete({{ $item->id }})"
                                    >
                                        Delete
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            No invoices have been issued yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="invoiceModal" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $invoice === null ? 'Add invoice' : 'Edit invoice' }}
                </flux:heading>
                <flux:text class="mt-1">What the customer will see on their copy.</flux:text>
            </div>

            <flux:input label="Title" wire:model="title" placeholder="e.g. Cohort enrolment" autofocus badge="required" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.number-field label="Amount" wire:model="amount" />

                <flux:select label="Channel" wire:model="channel">
                    @foreach ($channels as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <x-form.markdown-field label="Notes" wire:model="notes" placeholder="Anything the customer should know." markdown />

            <flux:switch wire:model="status" label="Issued" description="Turn off to keep this as a draft." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save invoice</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
```

## 7. Index page with filters ([pages.md](pages.md) shape B)

```php
<?php

use App\Enums\InvoiceChannelEnum;
use App\Enums\StatusInvoice;
use App\Models\Invoice;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $channelFilter = '';

    public function mount(): void
    {
        kSetSiteTitle('finance', 'invoices');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::query()
            ->with('user:id,name,avatar')
            ->when($this->search !== '', fn ($query) => $query->searchMacro(['reference', 'title'], $this->search))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->channelFilter !== '', fn ($query) => $query->where('channel', $this->channelFilter))
            ->latest()
            ->paginate(12);
    }

    #[Computed]
    public function statusOptions(): array
    {
        return StatusInvoice::forSelect();
    }

    #[Computed]
    public function channelOptions(): array
    {
        return InvoiceChannelEnum::forSelect();
    }

    #[Computed]
    public function metrics(): array
    {
        $issued = Invoice::query()->where('status', StatusInvoice::ISSUED)->count();
        $overdue = Invoice::query()->where('status', StatusInvoice::ISSUED)->where('due_at', '<', now())->count();
        $collected = (int) Invoice::query()->where('status', StatusInvoice::PAID)->sum('amount') / 100;

        return [
            ['label' => 'Issued', 'value' => number_format($issued), 'icon' => 'document-text', 'tone' => 'sky'],
            ['label' => 'Overdue', 'value' => number_format($overdue), 'icon' => 'clock', 'tone' => 'amber'],
            ['label' => 'Collected', 'value' => kMoneyFormat($collected), 'icon' => 'banknotes', 'tone' => 'emerald'],
        ];
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Invoice metrics">
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
                <flux:heading level="2" size="lg">Invoices</flux:heading>
                <flux:text class="mt-1">Everything issued, with what has been collected.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search reference or title"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="channelFilter">
                    <option value="">All channels</option>
                    @foreach ($this->channelOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="statusFilter">
                    <option value="">All statuses</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <flux:table :paginate="$this->invoices">
            <flux:table.columns>
                <flux:table.column>Customer</flux:table.column>
                <flux:table.column>Reference</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Issued</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->invoices as $item)
                    <flux:table.row wire:key="invoice-{{ $item->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <x-dashboard.avatar :user="$item->user" />
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $item->user->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $item->user->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->reference }}</flux:table.cell>
                        <flux:table.cell>{!! $item->amountMoney() !!}</flux:table.cell>
                        <flux:table.cell><x-util.e-badge :enum="$item->status" /></flux:table.cell>
                        <flux:table.cell>{{ $item->issuedAtHuman() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button
                                    icon="eye"
                                    variant="primary"
                                    size="sm"
                                    :href="route('admin.invoice', $item->reference)"
                                    wire:navigate
                                    title="View invoice"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <x-dashboard.workspace-no-record
                                label="Invoices"
                                icon="document-text"
                                text="No invoices match the current filters."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
```

## 8. Settings page ([pages.md](pages.md) shape C)

```php
<?php

use App\Rules\ImageRule;
use App\Services\SiteConfigurationService;
use App\Traits\WithFormResponseMessage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    public array $config;

    public array $currentConfig = [];

    public mixed $bannerUpload = null;

    public function mount(): void
    {
        kSetSiteTitle('config', 'invoicing');

        $service = app(SiteConfigurationService::class);
        $this->config = $service->getConfigs(mergeInitial: true, raw: true);
        $this->currentConfig = $service->getConfigs(raw: true);
    }

    protected function rules(): array
    {
        return [
            'config.invoicing.footer' => ['nullable', 'string', 'max:500'],
            'config.invoicing.due-days' => ['required', 'integer', 'min:1'],
            'bannerUpload' => [new ImageRule(required: false, size: 1024)],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        $this->respondPrimary(if: $this->config === $this->currentConfig && ! $this->bannerUpload);

        if ($this->bannerUpload) {
            // Store the new banner, then drop the one it replaces.
            $filename = kStoreFile($this->bannerUpload, filename: 'invoice-banner', path: 'site-config');
            kDeleteFile(data_get($this->config, 'invoicing.banner'));

            $this->config['invoicing']['banner'] = $filename;
        }

        app(SiteConfigurationService::class)->update($this->config);

        $this->reset('bannerUpload');

        $this->redirectRoute('admin.config.invoicing', navigate: true);

        return $this->respondSuccess();
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">Invoicing</flux:heading>
            <flux:text class="mt-1">How invoices are numbered, worded, and chased.</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-form.number-field label="Payment terms (days)" wire:model="config.invoicing.due-days" min="1" />
        </div>

        <x-form.markdown-field label="Invoice footer" wire:model="config.invoicing.footer" markdown />

        <x-form.image-field
            label="Invoice banner"
            wire:model="bannerUpload"
            :default="kSafeImage(data_get($config, 'invoicing.banner'))"
            :temporary="$bannerUpload?->temporaryUrl()"
            maxSize="1 MB"
        />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Save configuration</flux:button>
        </div>
    </flux:card>
</form>
```

## 9. Tab trait for a record ([traits.md](traits.md), [pages.md](pages.md) shape D)

```php
<?php

namespace App\Traits;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Livewire\Attributes\Computed;

/**
 * Shared by every admin invoice tab: owns the record, its lock, and the guard
 * every write goes through.
 *
 * @property-read bool $canUpdateInvoice
 */
trait WithInvoiceAdmin
{
    use WithFormResponseMessage;

    public Invoice $invoice;

    /**
     * Load what every invoice tab renders in its header.
     */
    protected function loadInvoice(): void
    {
        $this->invoice->load('user');
        $this->invoice->loadCount('lines');
    }

    #[Computed]
    public function canUpdateInvoice(): bool
    {
        return $this->invoice->allowUpdate();
    }

    /**
     * Refuse any write against a settled invoice, so the lock holds even when a
     * control is reached outside the rendered UI.
     */
    protected function ensureInvoiceIsEditable(): void
    {
        $this->respondError($this->invoice->lockedReason(), if: $this->invoice->isSettled());
    }

    /**
     * Refresh whatever the host page renders once the invoice changed. Pages override this.
     */
    protected function afterInvoiceChange(): void {}

    protected function invoiceService(): InvoiceService
    {
        return app(InvoiceService::class);
    }
}
```

## 10. Modal ([pages.md](pages.md), [ui.md](ui.md))

```blade
<flux:modal name="invoiceModal" class="md:w-150">
    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:heading size="lg">
                {{ $invoice === null ? 'Add invoice' : 'Edit invoice' }}
            </flux:heading>
            <flux:text class="mt-1">What the person filling this in needs to know.</flux:text>
        </div>

        <flux:input label="Title" wire:model="title" autofocus badge="required" />
        <flux:error name="title" />

        <flux:separator variant="subtle" />

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost" type="button">Cancel</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">Save invoice</flux:button>
        </div>
    </form>
</flux:modal>
```

Widths: `md:w-96` confirm · `md:w-150` standard form · `md:w-3xl` form with an editor.

## 11. Table ([tables.md](tables.md))

```blade
<flux:table :paginate="$this->things">
    <flux:table.columns>
        <flux:table.column>Identity</flux:table.column>
        <flux:table.column>Amount</flux:table.column>
        <flux:table.column>Status</flux:table.column>
        <flux:table.column>Created</flux:table.column>
        <flux:table.column>Actions</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($this->things as $item)
            <flux:table.row wire:key="thing-{{ $item->id }}">
                <flux:table.cell class="font-medium">{{ $item->name }}</flux:table.cell>
                <flux:table.cell>{!! $item->amountMoney() !!}</flux:table.cell>
                <flux:table.cell><x-util.e-badge :enum="$item->status" /></flux:table.cell>
                <flux:table.cell>{{ $item->createdAtHuman() }}</flux:table.cell>
                <flux:table.cell>
                    <flux:dropdown position="right" align="start">
                        <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $item->id }})">Edit</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item
                                icon="trash"
                                variant="danger"
                                wire:click="confirmDelete({{ $item->id }})"
                            >
                                Delete
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="5">
                    <x-dashboard.workspace-no-record label="Things" icon="folder-open" text="No things match the current filters." />
                </flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
```

## 12. Blade component ([components.md](components.md))

```blade
@props([
    'label',
    'value',
    'icon' => 'chart-bar',
    'tone' => 'slate',
    'hint' => null,
])

@php
    $toneClasses = match ($tone) {
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-300',
        'lime' => 'bg-lime-100 text-lime-700 dark:bg-lime-400/10 dark:text-lime-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };
@endphp

<div {{ $attributes->class('flex items-center justify-between gap-4')->merge() }}>
    <div class="min-w-0">
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{!! $label !!}</p>
        <p class="mt-1 font-heading text-xl font-bold text-slate-950 dark:text-white">{!! $value !!}</p>
        @if ($hint)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{!! $hint !!}</p>
        @endif
    </div>

    <span class="grid size-10 shrink-0 place-items-center rounded-lg {{ $toneClasses }}">
        <flux:icon :name="$icon" class="size-5" />
    </span>
</div>
```

## 13. Validation rule ([validation.md](validation.md))

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ReferenceRule implements ValidationRule
{
    public function __construct(
        private readonly string $prefix = 'INV-',
        private readonly bool $required = true,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->required && $value === null) {
            $fail('The :attribute field is required!');

            return;
        }

        // Skip checks if nullable and no value given
        if ($value === null) {
            return;
        }

        if (! str_starts_with((string) $value, $this->prefix)) {
            $fail("The :attribute must start with {$this->prefix}.");
        }
    }
}
```

## 14. Mailable ([mail.md](mail.md))

```php
<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use App\Traits\WithEmailResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceIssuedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithEmailResolver;

    public function __construct(
        public User $user,
        public Invoice $invoice,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your invoice {$this->invoice->reference} from ".$this->getEmailConfig()['name'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance.invoice-issued',
            with: [
                'amount' => $this->invoice->amountMoney(),
                'dueAt' => $this->invoice->dueAtHuman(),
            ],
        );
    }
}
```

## 15. Seeder ([seeders.md](seeders.md))

```php
<?php

namespace Database\Seeders;

use App\Enums\StatusInvoice;
use App\Models\InvoiceTemplate;
use Illuminate\Database\Seeder;

/**
 * The invoice templates finance starts from.
 *
 * Matched on the template name, so re-running refreshes the wording without
 * duplicating any of them. Editable afterwards under Admin → Finance → Templates.
 */
class InvoiceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $order => $template) {
            InvoiceTemplate::query()->updateOrCreate(
                ['name' => $template['name']],
                [...$template, 'flow_order' => $order + 1, 'status' => StatusInvoice::DRAFT]
            );
        }
    }

    /**
     * @return array<int, array{name: string, body: string}>
     */
    protected function templates(): array
    {
        $name = kSiteConfig('name');
        $site = \is_string($name) && $name !== '' ? $name : (string) config('app.name');

        return [
            ['name' => 'Cohort enrolment', 'body' => "Thank you for enrolling with {$site}. See [our terms](/terms#payments)."],
        ];
    }
}
```

## 16. Feature test ([testing.md](testing.md))

```php
<?php

use App\Enums\StatusInvoice;
use App\Enums\StatusUser;
use App\Services\RoleService;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create(['status' => StatusUser::ACTIVE]);
    $role = app(RoleService::class)->protectedRole();
    $admin->roles()->syncWithoutDetaching([$role->id]);

    $this->actingAs($admin);
});

/**
 * An invoice already issued to a customer.
 */
function seededInvoice(array $overrides = []): Invoice
{
    return Invoice::query()->create([
        'user_id' => User::factory()->create()->id,
        'reference' => 'INV-ABC12320260731',
        'title' => 'Cohort enrolment',
        'amount' => 5000000,
        'status' => StatusInvoice::ISSUED,
        'issued_at' => now(),
        ...$overrides,
    ]);
}

test('a new invoice is saved as a draft', function () {
    Livewire::test('pages::admin.finance.invoices')
        ->call('create')
        ->set('title', 'Cohort enrolment')
        ->set('amount', 50000)
        ->call('save')
        ->assertHasNoErrors();

    expect(Invoice::query()->count())->toBe(1);
});

test('a title is required', function () {
    Livewire::test('pages::admin.finance.invoices')
        ->call('create')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

test('a paid invoice can no longer be edited', function () {
    $invoice = seededInvoice(['status' => StatusInvoice::PAID]);

    Livewire::test('pages::admin.finance.invoice', ['invoice' => $invoice])
        ->set('title', 'Changed')
        ->call('save')
        ->assertHasErrors();

    expect($invoice->fresh()->title)->toBe('Cohort enrolment');
});
```

## 17. Console command ([commands.md](commands.md))

See the full template in [commands.md](commands.md).

## 18. Route + nav registration ([routing.md](routing.md))

```php
// routes/admin.php
Route::livewire('/invoices', 'pages::admin.finance.invoices')->name('invoices');
Route::livewire('/invoice/{invoice:reference}', 'pages::admin.finance.invoice')->name('invoice');
```

```php
// app/Helpers/navigations.php → 'admin' → 'finance' → 'children'
'invoices' => [
    'label' => 'Invoices',
    'link' => route('admin.invoices'),
],
```

```php
// the page's mount() — keys MUST match the nav keys above
kSetSiteTitle('finance', 'invoices');
```
