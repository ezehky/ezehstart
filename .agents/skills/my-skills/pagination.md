# pagination.md

## Rule

```php
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Computed]
    public function students()
    {
        return User::query()
            …
            ->latest()
            ->paginate(12);
    }
};
```

```blade
<flux:table :paginate="$this->students">
```

Three parts, all required together:

1. `use WithPagination;` on the component
2. `->paginate(12)` at the end of the computed query
3. `:paginate="$this->collection"` on `<flux:table>`

`flux:table` renders the pager itself — there is no `{{ $collection->links() }}`
anywhere in the project.

### Page size

**12** is the project default. `paginate(12)` appears on every paginated listing.
Deviate only with a reason (a denser table, a nested list) and keep it to a multiple
that fills the grid.

### Reset on filter change

Every filter's `updated*()` hook calls `resetPage()`:

```php
public function updatedSearch(): void
{
    $this->resetPage();
}
```

Without it a user filtering from page 4 sees an empty table.

### The computed's return type

Paginated computeds are left **untyped**:

```php
#[Computed]
public function students()          // no : LengthAwarePaginator
```

Non-paginated computeds declare `Collection` or `array`:

```php
#[Computed]
public function trainings(): Collection
```

Follow that split.

### When not to paginate

Short, bounded, config-style lists are fetched whole:

```php
#[Computed]
public function trainings(): Collection
{
    return Training::query()->select('id', 'name', 'slug', 'training_type', 'status')->latest()->get();
}
```

```blade
<flux:table>
    …
</flux:table>
```

No `WithPagination`, no `:paginate`. Use this for lists that are structurally small
(trainings, roles, trainer roles, FAQs, policies, cohort schedules).

> `⚡cohorts.blade.php` and `⚡trainings.blade.php` pass `:paginated="$this->x"` with a
> plain `Collection`. `:paginated` is **not** a Flux prop and does nothing. Do not
> copy it — either paginate properly with `:paginate`, or pass nothing.

### Ordering

Always order before paginating, or the pages are non-deterministic:

```php
->latest()        // newest first — listings
->oldest()        // longest waiting first — queues
->inFlowOrder()   // explicit display order — curriculum, FAQs
->orderBy('starts_at')
```

### Eager loading with pagination

Aggregates go in the query, never in the row loop:

```php
->with('userRoles.role')
->withCount(['admissions as enrolled_count' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED)])
->withSum(['admissions as amount_paid_sum' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED)], 'amount_paid')
```

### Metrics are counted separately

A metrics strip above a paginated table counts the **whole** set, not the page:

```php
#[Computed]
public function metrics(): array
{
    $total = User::query()->carriesRole(UserRoleEnum::STUDENT)->count();
    $active = User::query()->carriesRole(UserRoleEnum::STUDENT)->where('status', StatusUser::ACTIVE)->count();

    return [
        ['label' => 'Total students', 'value' => number_format($total), 'icon' => 'academic-cap', 'tone' => 'sky'],
        ['label' => 'Active accounts', 'value' => number_format($active), 'icon' => 'check-badge', 'tone' => 'emerald'],
    ];
}
```

Invalidate both after a write:

```php
protected function afterRoleChange(): void
{
    unset($this->students, $this->metrics);
}
```

## Why

- `WithPagination` keeps the page number in the URL, so a paginated + filtered view is
  one shareable link.
- Letting `flux:table` own the pager means the control looks identical on every
  listing and picks up dark mode and responsive behaviour for free.
- 12 rows fills the viewport on a laptop without scrolling past the card, and divides
  cleanly into the 2/3/4-column grids used elsewhere.
- Not paginating small config lists avoids a pager control that would never do
  anything.

## Example

`⚡students.blade.php`:

```php
use WithPagination, WithUserRoleManager;

#[Url(as: 'q')]
public string $search = '';

public function updatedSearch(): void
{
    $this->resetPage();
}

#[Computed]
public function students()
{
    return User::query()
        ->carriesRole(UserRoleEnum::STUDENT)
        ->with('userRoles.role')
        ->withCount([…])
        ->withSum([…], 'amount_paid')
        ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
        ->latest()
        ->paginate(12);
}
```

```blade
<flux:table :paginate="$this->students">
```

## Template

```php
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::query()
            ->with('user:id,name,avatar')
            ->when($this->search !== '', fn ($query) => $query->searchMacro(['reference', 'title'], $this->search))
            ->latest()
            ->paginate(12);
    }
};
```

```blade
<flux:table :paginate="$this->invoices">
    …
</flux:table>
```

## Avoid

- `->paginate()` without `use WithPagination;` — the links will full-page reload.
- `{{ $items->links() }}` — `flux:table` renders the pager.
- `:paginated=` — not a real prop.
- `simplePaginate()` / `cursorPaginate()` — neither is used here.
- Paginating without an `orderBy` / `latest()`.
- Forgetting `resetPage()` in a filter hook.
- A page size other than 12 without a reason.
- Counting metrics from the paginator (`$this->students->count()` is the page count,
  not the total).
- `->get()` then `->forPage()` in PHP.
- Queries inside the row loop on a paginated table.
