# dashboard.md

## Rule

A dashboard is: **greeting header → metric cards → chart → feeds**. Everything after
`mount()` is `#[Computed]`.

```php
public User $user;

public string $monthExpression;

public function mount(): void
{
    $this->user = auth()->user();
    kSetSiteTitle('dashboard');

    $this->monthExpression = match (DB::connection()->getDriverName()) {
        'sqlite' => "strftime('%Y-%m', created_at)",
        'pgsql' => "to_char(created_at, 'YYYY-MM')",
        default => "date_format(created_at, '%Y-%m')",
    };
}
```

Root spacing is `space-y-7` on dashboards (`space-y-6` everywhere else).

### The greeting header

```blade
<section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Administration</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-slate-950 dark:text-white">
            {{ kGreeting(str($user->name)->before(' '), false) }}.
        </h1>
        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">Here is the health of your learning business today.</p>
    </div>
    <flux:button variant="primary" icon="user-plus" class="press w-full sm:w-auto">Add User</flux:button>
</section>
```

`kGreeting($firstName, false)` gives "Good Morning, Kingsley" — the `false` drops the
exclamation mark so the template can end with a full stop.

### Metrics — `#[Computed] metrics(): array`

**Admin dashboard** returns a **keyed** array (each tile is addressable):

```php
#[Computed]
public function metrics(): array
{
    $usersCount = User::query()->count();
    $revenue = (int) Transaction::query()
        ->where('status', StatusTransaction::CONFIRMED)
        ->whereIn('transaction_type', [TransactionTypeEnum::CREDIT, TransactionTypeEnum::DIRECT])
        ->sum('amount') / 100;

    return [
        'users' => [
            'label' => 'Total users',
            'value' => number_format($usersCount),
            'icon' => 'users',
            'change' => 'All registered accounts',
            'tone' => 'sky',
        ],
        'revenue' => [
            'label' => 'Total revenue',
            'value' => kMoneyFormat($revenue),
            'icon' => 'banknotes',
            'change' => 'Confirmed credits and direct payments',
            'tone' => 'emerald',
        ],
        …
    ];
}
```

**Index pages** return a **list** array (rendered in order):

```php
return [
    ['label' => 'Total students', 'value' => number_format($total), 'icon' => 'academic-cap', 'tone' => 'sky'],
    ['label' => 'Active accounts', 'value' => number_format($active), 'icon' => 'check-badge', 'tone' => 'emerald'],
    ['label' => 'Enrolled admissions', 'value' => number_format($enrolled), 'icon' => 'ticket', 'tone' => 'slate'],
];
```

Every tile has exactly these keys: `label`, `value`, `icon`, `tone`, and optionally
`change` (the small caption underneath).

Formatting rules:

- counts → `number_format()`
- money → `kMoneyFormat()` (raw `sum()` is minor units, so divide by 100)
- `tone` is one of the six: `lime`, `emerald`, `sky`, `amber`, `rose`, `slate`

Rendering:

```blade
<section class="grid gap-4 sm:grid-cols-3" aria-label="Student metrics">
    @foreach ($this->metrics as $metric)
        <x-dashboard.stat-card
            :label="$metric['label']"
            :value="$metric['value']"
            :icon="$metric['icon']"
            :tone="$metric['tone']"
        />
    @endforeach
</section>
```

Grids: `sm:grid-cols-3` for three tiles, `sm:grid-cols-2 lg:grid-cols-3` for six.
The `<section>` carries an `aria-label`.

### Charts — CSS bars, no library

There is **no charting library**. A series is a `Collection` of objects carrying a
pre-computed `heightPercentage`:

```php
#[Computed]
public function revenueByMonth(): Collection
{
    $result = Transaction::query()
        ->selectRaw("{$this->monthExpression} as month, sum(amount) as total")
        ->where('status', StatusTransaction::CONFIRMED)
        ->whereIn('transaction_type', [TransactionTypeEnum::CREDIT, TransactionTypeEnum::DIRECT])
        ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
        ->groupBy('month')
        ->orderBy('month')
        ->get();

    $highestRevenue = max(1, (int) $result->max('total'));

    return $result->map(fn ($item) => (object) [
        'month' => $item->month,
        'label' => Carbon::createFromFormat('Y-m', $item->month)->format('F Y'),
        'monthShort' => Carbon::createFromFormat('Y-m', $item->month)->format('M'),
        'total' => $item->total / 100,
        'formattedTotal' => kMoneyFormat($item->total / 100),
        'heightPercentage' => max(4, round(($item->total / $highestRevenue) * 100)),
    ]);
}
```

Points:

- `max(1, …)` on the divisor — never divide by zero on an empty month set.
- `max(4, …)` on the height — a zero-height bar looks like a rendering bug.
- Both the raw and the formatted value are carried, so the bar has a tooltip and a
  label without re-formatting in Blade.
- Rows are cast to `(object)` so Blade reads `$bar->label`, matching model access.
- The driver-specific month expression is resolved once in `mount()`.

### Feeds — delegate to services

```php
#[Computed]
public function upcomingCohorts(): Collection
{
    return app(TrainingService::class)->getUpcomingCohorts();
}

#[Computed]
public function recentActivities(): Collection
{
    return app(ActivityLogService::class)->getActivityLogsForUser(auth()->user());
}
```

A dashboard **queries for its own metrics** but **delegates domain feeds** to the
service that owns them.

### The dashboard component set

| Component | Use |
| --- | --- |
| `x-dashboard.stat-card` | the standard metric tile |
| `x-dashboard.mini-stat` | compact inline stat |
| `x-dashboard.stat-pill` | a stat as a chip |
| `x-dashboard.icon-box` | toned icon container (`xs`/`sm`/default) |
| `x-dashboard.progress-ring` | circular progress |
| `x-dashboard.item` | a feed row |
| `x-dashboard.avatar` | user avatar with initials fallback (`sm`/`md`/`lg`) |
| `x-dashboard.workspace-no-record` | empty state |
| `x-dashboard.tab-nav` | the tab bar for record pages |

### Invalidation

Any action that changes the underlying data must clear both the list and the metrics:

```php
protected function afterRoleChange(): void
{
    unset($this->students, $this->metrics);
}
```

Metrics count the **whole** set, never the current page.

## Why

- Computed metrics mean the counts are recomputed only when the page renders and can be
  invalidated individually after a write.
- CSS bars instead of a chart library keep the JS bundle to Livewire + Alpine, work in
  dark mode for free, and need no server-side rendering fallback.
- Pre-computing `heightPercentage` in PHP keeps the Blade a plain loop with no
  arithmetic.
- Branching the month expression by driver lets the same dashboard run against SQLite
  in tests and MySQL in production.
- Fixing the tile shape (`label`, `value`, `icon`, `tone`, `change`) lets one component
  render every metric in the app.

## Example

The admin dashboard's structure, in order:

```blade
<div class="space-y-7">
    <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">…greeting…</section>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Key metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card
                :label="$metric['label']"
                :value="$metric['value']"
                :icon="$metric['icon']"
                :change="$metric['change']"
                :tone="$metric['tone']"
            />
        @endforeach
    </section>

    <flux:card class="space-y-6">…revenue bars…</flux:card>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card>…upcoming cohorts…</flux:card>
        <flux:card>…recent activity…</flux:card>
    </div>
</div>
```

## Template

```php
#[Computed]
public function metrics(): array
{
    $issued = Invoice::query()->where('status', StatusInvoice::ISSUED)->count();
    $overdue = Invoice::query()->where('status', StatusInvoice::ISSUED)->where('due_at', '<', now())->count();
    $collected = (int) Invoice::query()->where('status', StatusInvoice::PAID)->sum('amount') / 100;

    return [
        ['label' => 'Issued invoices', 'value' => number_format($issued), 'icon' => 'document-text', 'tone' => 'sky'],
        ['label' => 'Overdue', 'value' => number_format($overdue), 'icon' => 'clock', 'tone' => 'amber'],
        ['label' => 'Collected', 'value' => kMoneyFormat($collected), 'icon' => 'banknotes', 'tone' => 'emerald'],
    ];
}
```

```blade
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
```

## Avoid

- Assigning metrics in `mount()` — they must be `#[Computed]`.
- Adding Chart.js, ApexCharts, or any charting dependency.
- Dividing by a max that could be zero, or letting a bar render at 0% height.
- Raw `sum('amount')` without `/ 100` (money is stored in minor units).
- `{{ number_format($x) }}` in Blade — format in the computed.
- A tone outside the six-tone palette.
- Metrics derived from the paginator instead of a `count()` over the full set.
- Forgetting `unset($this->metrics)` after a write.
- Querying a domain feed inline instead of calling the owning service.
- Raw driver-specific SQL without the `match` on `getDriverName()`.
- A metric grid without an `aria-label`.
