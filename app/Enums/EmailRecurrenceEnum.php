<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;
use Illuminate\Support\Carbon;

/**
 * How often a campaign repeats itself.
 *
 * A repeat is not a campaign sending twice — a sent campaign is the record of what
 * went out and never changes. Finishing a recurring send instead spawns the next
 * occurrence as its own draft, scheduled at the moment this case works out. See
 * EmailCampaignService::spawnNextOccurrence().
 */
enum EmailRecurrenceEnum: string
{
    use WithEnumHelpers;

    case NONE = 'none';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case FORTNIGHTLY = 'fortnightly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';

    public function isNone(): bool
    {
        return $this === self::NONE;
    }

    public function isRepeating(): bool
    {
        return ! $this->isNone();
    }

    /**
     * The moment after $from this case lands on, or null when it never repeats.
     *
     * Always resolved from the send that just happened rather than from the first
     * one in the series, so a run held up by an outage does not immediately fire
     * every occurrence it missed.
     */
    public function next(Carbon $from): ?Carbon
    {
        $at = $from->copy();

        return match ($this) {
            self::NONE => null,
            self::DAILY => $at->addDay(),
            self::WEEKLY => $at->addWeek(),
            self::FORTNIGHTLY => $at->addWeeks(2),
            self::MONTHLY => $at->addMonthNoOverflow(),
            self::QUARTERLY => $at->addMonthsNoOverflow(3),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NONE => 'Sends once and stops.',
            self::DAILY => 'Repeats every day.',
            self::WEEKLY => 'Repeats on the same weekday.',
            self::FORTNIGHTLY => 'Repeats every second week.',
            self::MONTHLY => 'Repeats on the same date each month.',
            self::QUARTERLY => 'Repeats every three months.',
        };
    }
}
