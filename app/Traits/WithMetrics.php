<?php

namespace App\Traits;

use InvalidArgumentException;

/**
 * The metric tiles a screen opens with.
 *
 * Every dashboard and most listings put the same row of figures above the table, and
 * every one of them was building the tile by hand as an array of five loose keys. A
 * key spelled wrong in an array is silently ignored, and a tile that quietly lost its
 * icon or fell back to grey is not a thing anybody notices.
 *
 * `metricMaker()` is the same array, named — the editor lists what is on offer, a
 * typo is a fatal error, and a tone that is not in the palette says so instead of
 * rendering grey.
 *
 * WithDataTable pulls this in, so every listing already has it.
 */
trait WithMetrics
{
    /**
     * The tones <x-dashboard.stat-card> actually draws. Anything else renders grey
     * and says nothing about why.
     */
    protected const METRIC_TONES = ['slate', 'emerald', 'amber', 'sky', 'rose', 'lime'];

    /**
     * One tile, ready for <x-dashboard.stat-card>.
     *
     *     $this->metricMaker('Awaiting review', $pending, 'clock', tone: $pending > 0 ? 'amber' : 'slate'),
     *     $this->metricMaker('Deposits', kMoneyFormat($in, decodeHtml: true), 'banknotes', tone: 'emerald', trend: $this->ledgerTrends['deposits']),
     *
     * @param  string  $label  What the figure is
     * @param  int|float|string  $value  A number is formatted; a string is trusted as it stands
     * @param  string  $icon  The Heroicon in the corner
     * @param  string  $tone  One of METRIC_TONES — the colour of the icon and the sparkline
     * @param  string|null  $change  The line under the figure, saying what it counts
     * @param  array<int, object>|null  $trend  A TrendService series, drawn as a sparkline
     * @param  string  $trendField  Which field of that series is the value
     * @return array<string, mixed>
     */
    protected function metricMaker(
        string $label,
        int|float|string $value,
        string $icon,
        string $tone = 'slate',
        ?string $change = null,
        ?array $trend = null,
        string $trendField = 'total',
    ): array {
        if (! \in_array($tone, self::METRIC_TONES, true)) {
            throw new InvalidArgumentException("Unknown metric tone: {$tone}. Use one of: ".implode(', ', self::METRIC_TONES));
        }

        return [
            'label' => $label,
            // A raw count is formatted here so no screen has to remember to. Money
            // and percentages arrive already written, as strings, and are left alone.
            'value' => is_string($value) ? $value : number_format($value),
            'icon' => $icon,
            'tone' => $tone,
            'change' => $change,
            'trend' => $trend,
            'trendField' => $trendField,
        ];
    }
}
