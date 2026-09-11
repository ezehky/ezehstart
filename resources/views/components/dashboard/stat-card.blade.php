{{--
    The metric tile every dashboard opens with.

        <x-dashboard.stat-card label="Orders" value="1,013" icon="shopping-bag" tone="sky" />

        <x-dashboard.stat-card
            label="Orders"
            :value="number_format($total)"
            icon="shopping-bag"
            tone="sky"
            :trend="$this->ordersByMonth"
            trend-field="total"
        />

    With `trend`, the figure gets a sparkline under it: the same rows <x-chart> takes,
    drawn with no axes, no grid and no tooltip. A single number says where you are and
    nothing about how you got there, and "up from a dip" and "down from a peak" are
    not the same news.

    It is the chart set doing the drawing, not a second implementation — the same
    renderer, asked for less.
--}}

@props([
    'label',
    'value',
    'icon',
    'change' => null,
    'tone' => 'slate',
    'trend' => null,
    'trendField' => 'total',
])

@php
    $toneClasses = match ($tone) {
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-300',
        'lime' => 'bg-lime-100 text-lime-700 dark:bg-lime-400/10 dark:text-lime-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };

    // The line takes the tile's own tone, so a card reads as one thing rather than as
    // a figure with somebody else's chart under it.
    $trendClasses = match ($tone) {
        'emerald' => 'text-emerald-500 dark:text-emerald-400',
        'amber' => 'text-amber-500 dark:text-amber-400',
        'sky' => 'text-sky-500 dark:text-sky-400',
        'rose' => 'text-rose-500 dark:text-rose-400',
        'slate' => 'text-slate-400 dark:text-slate-500',
        default => 'text-lime-500 dark:text-lime-400',
    };

    $trend = $trend instanceof \Illuminate\Support\Collection ? $trend->values()->all() : $trend;

    // One point draws nothing a reader can use — a line needs somewhere to go.
    $hasTrend = is_array($trend) && count($trend) > 1;
@endphp

<flux:card {{ $attributes->merge() }}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{!! $label !!}</p>
            <p class="mt-2 font-heading text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                {!! $value !!}
            </p>
        </div>
        <span class="grid size-10 place-items-center rounded-lg {{ $toneClasses }}">
            <flux:icon :name="$icon" class="size-5" />
        </span>
    </div>

    @if ($hasTrend)
        {{-- Bled to the card's edges: the shape is the point, and a sparkline inset
             from the text above it reads as a second, smaller chart. --}}
        <x-chart :value="$trend" gutter="0 0 1 0" class="-mx-6 -mb-6 mt-4 h-14">
            <x-chart.svg>
                <x-chart.area :field="$trendField" :opacity="0.12" class="{{ $trendClasses }}" />
                <x-chart.line :field="$trendField" :width="2" class="{{ $trendClasses }}" />
            </x-chart.svg>
        </x-chart>
    @endif

    @if ($change)
        <p class="mt-5 text-xs font-medium text-slate-500 dark:text-slate-400">{!! $change !!}</p>
    @endif
</flux:card>
