<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The bucket a trend is counted in.
 *
 * Grouping a date column is the one place raw SQL is unavoidable, and every driver
 * spells it differently. Each case owns its own spelling, so a screen asking for a
 * different bucket changes one argument rather than a `match` of its own.
 *
 * There is no WEEK case on purpose: SQLite has no ISO week, so the three drivers
 * cannot agree on what the bucket key means, and a series whose keys disagree with
 * the window it is padded against draws nothing. Day, month and year align exactly.
 */
enum TrendPeriodEnum: string
{
    use WithEnumHelpers;

    case DAY = 'day';
    case MONTH = 'month';
    case YEAR = 'year';

    public function isDay(): bool
    {
        return $this === self::DAY;
    }

    public function isMonth(): bool
    {
        return $this === self::MONTH;
    }

    public function isYear(): bool
    {
        return $this === self::YEAR;
    }

    /**
     * The SQL that turns a date column into this bucket's key, for the connected
     * driver. MySQL and MariaDB take the `default` arm.
     */
    public function expression(string $column = 'created_at', ?string $driver = null): string
    {
        $driver ??= DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('{$this->sqliteFormat()}', {$column})",
            'pgsql' => "to_char({$column}, '{$this->postgresFormat()}')",
            default => "date_format({$column}, '{$this->sqliteFormat()}')",
        };
    }

    /**
     * The PHP date format that produces the same key the SQL above does. The window
     * is padded in PHP, so the two have to spell a bucket identically.
     */
    public function keyFormat(): string
    {
        return match ($this) {
            self::DAY => 'Y-m-d',
            self::MONTH => 'Y-m',
            self::YEAR => 'Y',
        };
    }

    /** The long label, for a tooltip heading. */
    public function labelFormat(): string
    {
        return match ($this) {
            self::DAY => 'j F Y',
            self::MONTH => 'F Y',
            self::YEAR => 'Y',
        };
    }

    /** The short label, for an axis tick. */
    public function shortFormat(): string
    {
        return match ($this) {
            self::DAY => 'j M',
            self::MONTH => 'M',
            self::YEAR => 'Y',
        };
    }

    /** Step a date back by whole buckets, landing on the start of one. */
    public function stepBack(CarbonInterface $from, int $steps): CarbonInterface
    {
        return match ($this) {
            self::DAY => $from->copy()->subDays($steps)->startOfDay(),
            self::MONTH => $from->copy()->subMonths($steps)->startOfMonth(),
            self::YEAR => $from->copy()->subYears($steps)->startOfYear(),
        };
    }

    /**
     * SQLite's strftime and MySQL's date_format happen to share this vocabulary for
     * the three buckets here, so one string serves both.
     */
    protected function sqliteFormat(): string
    {
        return match ($this) {
            self::DAY => '%Y-%m-%d',
            self::MONTH => '%Y-%m',
            self::YEAR => '%Y',
        };
    }

    protected function postgresFormat(): string
    {
        return match ($this) {
            self::DAY => 'YYYY-MM-DD',
            self::MONTH => 'YYYY-MM',
            self::YEAR => 'YYYY',
        };
    }
}
