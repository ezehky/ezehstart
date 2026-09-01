# search.md

## Rule

All text search goes through **`searchMacro`**, a Builder macro registered in
`AppServiceProvider::boot()`. There is no Scout, no full-text index, no repository
search method.

```php
$query->searchMacro(['name', 'email', 'phone_number'], $this->search);
$query->searchMacro('name', $this->search);            // single column also works
```

### The macro

```php
// app/Providers/AppServiceProvider.php
Builder::macro('searchMacro', function ($columns, $search) {
    if ($search) {
        if (\is_array($columns)) {
            $this->where(function ($query) use ($columns, $search) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%{$search}%");
                }
            });

            return $this;
        } else {
            return $this->where($columns, 'like', '%'.$search.'%');
        }
    } else {
        return $this;
    }
});
```

Behaviour:

- Array of columns → grouped `OR LIKE` inside a single `where(function …)`, so it
  cannot leak out and swallow sibling `AND` conditions.
- Single column → a plain `LIKE`.
- Empty/falsy `$search` → the builder is returned untouched.

Because the macro already no-ops on an empty term, wrapping it in `when()` is belt and
braces — but the project **does** wrap it, for symmetry with the other filters:

```php
->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email'], $this->search))
```

Follow that. Consistency with the surrounding filter chain wins.

### Choosing columns

Search the columns the user can actually see or would type:

| Listing | Columns |
| --- | --- |
| Students / trainers / admins | `['name', 'email', 'phone_number']` |
| Transactions | `['reference', 'description']` |
| Activity logs | `['description']` |
| Cohorts / trainings | `['name']` |

Never search an id, an enum backing value, a hashed column, or a JSON blob.

### Searching a related table

Wrap in `whereHas` and apply the macro inside:

```php
->when($this->search !== '', fn ($query) => $query
    ->whereHas('user', fn ($user) => $user->searchMacro(['name', 'email'], $this->search)))
```

To search both the record and its relation, group them:

```php
->when($this->search !== '', fn ($query) => $query->where(fn ($group) => $group
    ->searchMacro(['reference', 'description'], $this->search)
    ->orWhereHas('user', fn ($user) => $user->searchMacro(['name', 'email'], $this->search))))
```

### The input

```blade
<flux:input
    class="sm:min-w-60"
    wire:model.live.debounce.350ms="search"
    placeholder="Search name, email or phone"
    icon="magnifying-glass"
/>
```

- **`350ms` debounce** is the project standard. Do not use a different value.
- `icon="magnifying-glass"`.
- The placeholder **lists the searched fields** so the user knows what will match.

### The property

```php
#[Url(as: 'q')]
public string $search = '';

public function updatedSearch(): void
{
    $this->resetPage();
}
```

`as: 'q'` shortens the query string. See [filters.md](filters.md).

## Why

- A macro rather than a trait or scope means **any** builder in the app gets search for
  free, including relation builders inside `whereHas`, with no per-model code.
- Grouping the `orWhere` set inside a closure is the whole point — without it, a
  three-column search would OR itself past the role and status filters and return the
  entire table.
- `LIKE` is adequate at this data scale, is portable across the SQLite (test), MySQL,
  and PostgreSQL targets the dashboard already branches on, and needs no index
  maintenance or search daemon.
- One fixed debounce keeps request volume predictable across every listing.

## Example

`⚡students.blade.php`:

```php
->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
```

```blade
<flux:input
    class="sm:min-w-60"
    wire:model.live.debounce.350ms="search"
    placeholder="Search name, email or phone"
    icon="magnifying-glass"
/>
```

## Template

```php
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
        ->with('user:id,name')
        ->when($this->search !== '', fn ($query) => $query->where(fn ($group) => $group
            ->searchMacro(['reference', 'title'], $this->search)
            ->orWhereHas('user', fn ($user) => $user->searchMacro(['name', 'email'], $this->search))))
        ->latest()
        ->paginate(12);
}
```

```blade
<flux:input
    class="sm:min-w-60"
    wire:model.live.debounce.350ms="search"
    placeholder="Search reference, title or customer"
    icon="magnifying-glass"
/>
```

## Avoid

- Hand-writing `->where('name', 'like', "%{$search}%")->orWhere(…)` — use the macro.
- `orWhere` chains outside a grouping closure (they escape and break other filters).
- Adding Laravel Scout, Meilisearch, or a full-text index.
- A different debounce value, or none at all.
- `wire:model` (deferred) on a search box — it must be `.live`.
- Searching hashed, encrypted, or enum-backed columns.
- Building a `SearchService` or a repository search method.
- Filtering the collection in PHP after `get()`.
