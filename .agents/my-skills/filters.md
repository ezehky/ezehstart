# filters.md

## Rule

Filters are `#[Url]` public properties, an `updated{Prop}(): void` hook that calls
`resetPage()`, and a `->when()` clause in the listing's `#[Computed]`.

```php
use Livewire\Attributes\Url;

#[Url(as: 'q')]
public string $search = '';

#[Url]
public string $accountStatus = '';

#[Url]
public string $cohortId = '';

public function updatedSearch(): void
{
    $this->resetPage();
}

public function updatedAccountStatus(): void
{
    $this->resetPage();
}

public function updatedCohortId(): void
{
    $this->resetPage();
}
```

**One `updated*()` method per filter.** They are not combined.

### The empty sentinel is `''`, not `null`

Every filter property is a `string` initialised to `''`, and "no filter" is the empty
string. The `<option value="">` in the select supplies it.

```php
->when($this->accountStatus !== '', fn ($query) => $query->where('status', $this->accountStatus))
```

Compare with `!== ''` — never `filled()`, `!empty()`, or a truthy check (a status of
`'0'` is a legitimate value).

### Query composition

The listing is one `#[Computed]` built as a single chain: base scope → eager loads →
aggregates → filters → order → paginate.

```php
#[Computed]
public function students()
{
    return User::query()
        ->users()
        ->with('userRoles.role')
        ->withCount([
            'admissions as enrolled_count' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED),
        ])
        ->withSum(
            ['admissions as amount_paid_sum' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED)],
            'amount_paid'
        )
        ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
        ->when($this->accountStatus !== '', fn ($query) => $query->where('status', $this->accountStatus))
        ->when($this->cohortId !== '', fn ($query) => $query
            ->whereHas('admissions', fn ($admission) => $admission->where('cohort_id', $this->cohortId)))
        ->latest()
        ->paginate(12);
}
```

Nested closures name their parameter after the relation being constrained —
`fn ($admission) => …`, `fn ($roleQuery) => …`, `fn ($cohort) => …` — while the outer
one stays `$query`.

### Option sources

Enum-backed filters get a `#[Computed]` returning `forSelect()`:

```php
#[Computed]
public function statusOptions(): array
{
    return StatusUser::forSelect();
}
```

Model-backed filters get a `#[Computed]` returning a `Collection`:

```php
#[Computed]
public function cohorts(): Collection
{
    return Cohort::query()->with('training:id,name')->orderByDesc('id')->get();
}
```

### Declaring them — `filterMaker()`

A listing on `WithDataTable` declares its filters once, and the trait renders the chip
bar off that one declaration. Build each with **`filterMaker()`**:

```php
protected function tableFilters(): array
{
    return [
        'search' => $this->filterMaker('Search'),
        'status' => $this->filterMaker('Status', StatusTransaction::forSelect()),
        'group' => $this->filterMaker('Type', TransactionGroupEnum::forSelect()),
    ];
}
```

The key is the **property** the filter is bound to. The second argument is the same
list the select is built from — without it the chip reads "Status: 2" instead of
"Status: Confirmed". A search box has no options, so it takes only a label.

### The filter bar

Sits in the card header, to the right of the heading:

```blade
<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
        <flux:heading level="2" size="lg">Students</flux:heading>
        <flux:text class="mt-1">Everyone who can enroll in a cohort, with what they have joined and paid.</flux:text>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input
            class="sm:min-w-60"
            wire:model.live.debounce.350ms="search"
            placeholder="Search name, email or phone"
            icon="magnifying-glass"
        />
        <flux:select wire:model.live="cohortId">
            <option value="">All cohorts</option>
            @foreach ($this->cohorts as $cohort)
                <option value="{{ $cohort->id }}">{{ $cohort->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="accountStatus">
            <option value="">All statuses</option>
            @foreach ($this->statusOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
    </div>
</div>
```

Conventions:

- Search input first, then selects, ordered broad → narrow.
- Search: `wire:model.live.debounce.350ms`, `icon="magnifying-glass"`,
  `class="sm:min-w-60"`, placeholder lists the searched fields.
- Selects: `wire:model.live` (no debounce), first option is
  `<option value="">All {things}</option>`.
- **Filter bars use bare `<option>`**, not `<flux:select.option>`. Forms use
  `<flux:select.option>`. Both appear in the project; keep the distinction.
- `flex flex-col gap-3 sm:flex-row` so filters stack on mobile.

### URL binding

`#[Url]` puts the value in the query string, so a filtered view is shareable and
survives a refresh. Shorten only the search key:

```php
#[Url(as: 'q')]
public string $search = '';
```

Everything else keeps its property name (`?accountStatus=1&cohortId=4`).

### The empty state must mention filters

```blade
<x-dashboard.workspace-no-record
    label="Students"
    icon="academic-cap"
    text="No students match the current filters."
/>
```

## Why

- `#[Url]` makes an admin's view linkable — support can paste a URL rather than
  describe six dropdown positions.
- `resetPage()` on every filter change prevents the "page 4 of a 2-page result" empty
  screen.
- `''` as the sentinel keeps the property a plain `string`, which serialises cleanly to
  the query string and needs no null handling in the Blade `<option>` loop.
- Building the whole query in one `->when()` chain means the filter logic is readable
  top-to-bottom and there is no branching over partially-built builders.

## Example

`⚡transactions.blade.php` and `⚡activity-logs.blade.php` follow the identical shape
with different filter sets. `⚡students.blade.php` (quoted above) is the reference.

## Template

```php
use Livewire\Attributes\Url;
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
};
```

```blade
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
```

## Avoid

- Writing a filter as an array literal instead of `$this->filterMaker(...)`.

- A filter property without `#[Url]`.
- Forgetting `resetPage()` — the user lands on an empty page.
- One combined `updated()` hook instead of one per property.
- `null` as the "no filter" value.
- `when($this->x)` (truthy) instead of `when($this->x !== '')` — breaks on `'0'`.
- Applying filters in PHP after `get()` instead of in the query.
- `wire:model.live` on the search box without a debounce (a request per keystroke).
- A debounce on a `<select>` — selects change discretely.
- Loading options inside the Blade loop (`{{ Cohort::all() }}`) — use `#[Computed]`.
- An empty state that does not tell the user filters are active.
