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

### Charts — `<x-chart>`, still no library

There is **no charting library**, and there is not going to be one. A chart is an
`<svg>` drawn by `resources/js/chart.js`: the parent measures its own box, turns a
value into a pixel, and each nested component asks it for the shape it needs. The
composition mirrors Flux Pro's `<flux:chart>`, which is also plain SVG — nesting a
part is how you turn it on, and leaving it out is how you turn it off.

The computed produces **rows**, nothing more. No scaling, no percentages — that is
the engine's job now:

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

    return $result->map(fn ($item) => (object) [
        'month' => $item->month,
        'label' => Carbon::createFromFormat('Y-m', $item->month)->format('F Y'),
        'monthShort' => Carbon::createFromFormat('Y-m', $item->month)->format('M'),
        'total' => $item->total / 100,
    ]);
}
```

```blade
<x-chart :value="$this->revenueByMonth" gutter="8 8 28 48" class="h-52">
    <x-chart.svg>
        <x-chart.axis axis="y" :tick-count="4" :format="['notation' => 'compact']">
            <x-chart.axis.grid />
            <x-chart.axis.tick />
        </x-chart.axis>

        <x-chart.axis axis="x" field="monthShort">
            <x-chart.axis.line />
            <x-chart.axis.tick />
        </x-chart.axis>

        <x-chart.cursor type="area" />
        <x-chart.bar field="total" />
    </x-chart.svg>

    <x-chart.tooltip>
        <x-chart.tooltip.heading field="label" />
        <x-chart.tooltip.value field="total" label="Revenue" prefix="&#8358;" />
    </x-chart.tooltip>
</x-chart>
```

The component set:

| Component | Props | Draws |
| --- | --- | --- |
| `x-chart` | `value`, `gutter`, or `wire:model` | the Alpine root; `class` sets the height |
| `x-chart.svg` | — | the canvas, and the pointer tracking |
| `x-chart.line` | `field`, `curve`, `width` | a monotone or straight line |
| `x-chart.area` | `field`, `curve`, `opacity` | the fill under a line |
| `x-chart.bar` | `field`, `radius`, `width` | bars on a band scale |
| `x-chart.point` | `field`, `radius`, `stroke-width` | dots on each row |
| `x-chart.axis` | `axis`, `field`, `format`, `tick-count`, `tick-prefix`, `tick-suffix`, `min`, `max` | nothing — it holds the config its children read |
| `x-chart.axis.grid` / `.line` / `.tick` | — | gridlines, the baseline, the labels |
| `x-chart.cursor` | `type` (`line`, `area`) | the hover guide |
| `x-chart.tooltip` + `.heading` / `.value` | `field`, `label`, `format`, `prefix`, `suffix` | the hover readout |

Points:

- **Colour is `currentColor`.** `<x-chart.bar class="text-sky-500 dark:text-sky-400" />`
  is the whole theming story. Ticks are `<text>`, which inherits `fill` and not
  `color`, so those take a `fill-*` instead.
- **`gutter` reserves the room the ticks are drawn into**, read as CSS shorthand
  (`"8"`, `"8 12"`, `"8 8 28 40"`). Add a y axis and you need a left gutter, or the
  numbers render outside the box. This is the one place the set is less automatic
  than Flux Pro, which measures its own labels.
- **`:value` renders once; `wire:model` entangles** and keeps the series live across
  a round trip.
- `format` is passed straight to `Intl.NumberFormat` / `Intl.DateTimeFormat`, so it
  takes their option arrays.
- Rows are still cast to `(object)` so Blade reads `$row->label`, matching model
  access; they serialise to JSON either way.
- The driver-specific month expression is still resolved once in `mount()`.
- Guard the empty set with `@if ($this->rows->isEmpty())` and an empty state — an
  axis over nothing is not worth drawing.

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
- Hand-drawn SVG instead of a chart library keeps the JS bundle to Livewire + Alpine,
  works in dark mode for free through `currentColor`, and needs no server-side
  rendering fallback.
- Keeping the scaling in the engine rather than the computed means a page hands over
  the rows it already queried and nothing else, and the same series can be drawn as
  bars, a line or an area without touching the PHP.
- Composing the parts rather than configuring them means the markup says what is on
  screen: no gridlines in the Blade, no gridlines in the chart.
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
- Adding a chart library to get something `<x-chart>` already draws.
- A y axis with no left `gutter` — the tick labels render outside the box.
- Re-deriving percentages or heights in the computed; hand over the raw values.
- Raw `sum('amount')` without `/ 100` (money is stored in minor units).
- `{{ number_format($x) }}` in Blade — format in the computed.
- A tone outside the six-tone palette.
- Metrics derived from the paginator instead of a `count()` over the full set.
- Forgetting `unset($this->metrics)` after a write.
- Querying a domain feed inline instead of calling the owning service.
- Raw driver-specific SQL without the `match` on `getDriverName()`.
- A metric grid without an `aria-label`.
