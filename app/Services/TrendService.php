<?php

namespace App\Services;

use App\Enums\TrendPeriodEnum;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Trends over time — the series behind every sparkline and chart in the app.
 *
 * A total says where something stands; the line says whether it got there steadily
 * or in one week. Producing that line is the same six steps every time: bucket a
 * date column the way this driver spells it, read the window in one grouped query,
 * carve it into the series the screen asked for, and pad every bucket that nothing
 * landed in — a gap draws a line climbing through months that never happened.
 *
 * That is what lives here, so a new dashboard writes no SQL of its own.
 */
#[Singleton]
class TrendService
{
    // Getters

    /**
     * Every bucket key in the window, oldest first, whether anything landed in it
     * or not.
     *
     * @return Collection<int, string>
     */
    public function periods(int $count = 6, TrendPeriodEnum $period = TrendPeriodEnum::MONTH, ?Carbon $endingAt = null): Collection
    {
        $end = $endingAt ?? now();

        return collect(range($count - 1, 0))
            ->map(fn (int $back) => $period->stepBack($end, $back)->format($period->keyFormat()));
    }

    // Actions

    /**
     * One series — the common case, and what a single sparkline takes.
     *
     * ->trend(User::query())                                  a count a month, six months
     * ->trend(Order::query(), sum: 'total', divideBy: 100)     money a month, in major units
     * ->trend(Visit::query(), period: TrendPeriodEnum::DAY, periods: 30)
     *
     * @return array<int, object>
     */
    public function trend(
        Builder $query,
        ?string $sum = null,
        ?float $divideBy = null,
        int $periods = 6,
        TrendPeriodEnum $period = TrendPeriodEnum::MONTH,
        string $column = 'created_at',
        ?Carbon $endingAt = null,
    ): array {
        return $this->trends(
            query: $query,
            series: ['value' => ['sum' => $sum, 'divideBy' => $divideBy]],
            periods: $periods,
            period: $period,
            column: $column,
            endingAt: $endingAt,
        )['value'];
    }

    /**
     * Several series off one read of the table.
     *
     * `splitBy` names the columns the query groups on beyond the bucket itself, and
     * a series' `match` then carves the grouped rows by those columns in memory.
     * Three tiles on a dashboard cost one query, not three:
     *
     *     app(TrendService::class)->trends(
     *         Transaction::query(),
     *         splitBy: ['transaction_group', 'status'],
     *         series: [
     *             'all' => [],
     *             'deposits' => [
     *                 'match' => [
     *                     'transaction_group' => TransactionGroupEnum::DEPOSIT,
     *                     'status' => StatusTransaction::CONFIRMED,
     *                 ],
     *                 'sum' => 'amount',
     *                 'divideBy' => 100,
     *             ],
     *         ],
     *     );
     *
     * Each series is described by four optional keys:
     *
     * | Key | Means |
     * | --- | --- |
     * | `match` | column => value the grouped rows must equal. Enums are fine |
     * | `sum` | a column to total. Omitted, the series counts rows |
     * | `divideBy` | divide each point, for minor units stored as integers |
     * | `label` | what a tooltip calls the value. Defaults to the series key |
     *
     * Every point comes back as an object carrying `period`, `label`, `short` and
     * `total` — the shape `<x-chart>` and `<x-dashboard.stat-card>` both read.
     *
     * @param  array<string, array<string, mixed>>  $series
     * @param  array<int, string>  $splitBy
     * @return array<string, array<int, object>>
     */
    public function trends(
        Builder $query,
        array $series,
        array $splitBy = [],
        int $periods = 6,
        TrendPeriodEnum $period = TrendPeriodEnum::MONTH,
        string $column = 'created_at',
        ?Carbon $endingAt = null,
    ): array {
        $buckets = $this->periods($periods, $period, $endingAt);
        $rows = $this->read($query, $series, $splitBy, $periods, $period, $column, $endingAt);

        return collect($series)
            ->map(fn (array $definition) => $this->carve($rows, $buckets, $definition, $period))
            ->all();
    }

    // Tools

    /**
     * The one grouped read behind every series.
     *
     * Each summed column is selected once however many series ask for it, and the
     * count comes back whether anything wants it or not — it is free next to the
     * grouping.
     *
     * @param  array<string, array<string, mixed>>  $series
     * @param  array<int, string>  $splitBy
     * @return Collection<int, object>
     */
    protected function read(
        Builder $query,
        array $series,
        array $splitBy,
        int $periods,
        TrendPeriodEnum $period,
        string $column,
        ?Carbon $endingAt,
    ): Collection {
        $expression = $period->expression($column);

        $selects = collect($series)
            ->pluck('sum')
            ->filter()
            ->unique()
            ->map(fn (string $summed) => "sum({$summed}) as ".$this->alias($summed))
            ->prepend('count(*) as trend_count')
            ->prepend("{$expression} as trend_period")
            ->implode(', ');

        $from = $period->stepBack($endingAt ?? now(), $periods - 1);

        // `toBase()` on purpose: these rows are aggregates, not records. Hydrating a
        // model out of three grouped columns costs something and buys a record that
        // does not exist.
        return $query
            ->where($column, '>=', $from)
            ->when($endingAt, fn (Builder $scoped) => $scoped->where($column, '<=', $endingAt))
            ->toBase()
            ->selectRaw($selects.($splitBy === [] ? '' : ', '.implode(', ', $splitBy)))
            ->groupBy('trend_period', ...$splitBy)
            ->get();
    }

    /**
     * One series out of the grouped rows, padded to the full window.
     *
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, string>  $buckets
     * @param  array<string, mixed>  $definition
     * @return array<int, object>
     */
    protected function carve(Collection $rows, Collection $buckets, array $definition, TrendPeriodEnum $period): array
    {
        $summed = $definition['sum'] ?? null;
        $divideBy = $definition['divideBy'] ?? null;
        $field = $summed ? $this->alias($summed) : 'trend_count';

        $matched = collect($definition['match'] ?? [])
            ->reduce(
                // Collection::where compares loosely and unwraps a backed enum on
                // either side, so a match value may be given as the case or as the
                // stored value, and a driver handing an int column back as a string
                // is not a problem.
                fn (Collection $carried, $value, string $on) => $carried->where($on, $value),
                $rows,
            );

        return $buckets
            ->map(function (string $bucket) use ($matched, $field, $divideBy, $summed, $period) {
                $total = $matched->where('trend_period', $bucket)->sum($field);
                $total = $divideBy ? $total / $divideBy : $total;

                $at = Carbon::createFromFormat($period->keyFormat(), $bucket)->startOfDay();

                return (object) [
                    'period' => $bucket,
                    'label' => $at->format($period->labelFormat()),
                    'short' => $at->format($period->shortFormat()),
                    'total' => $summed || $divideBy ? (float) $total : (int) $total,
                ];
            })
            ->all();
    }

    /** A select alias no column of the table could collide with. */
    protected function alias(string $column): string
    {
        return 'trend_sum_'.str_replace('.', '_', $column);
    }
}
