<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The warnings sent while an account sits in its deletion grace period.
 *
 * A case is a lead time, measured in days before deletion_scheduled_at. Adding
 * one adds a reminder — the command iterates the cases and claims each per
 * account, so nothing else needs an edit.
 */
enum DeletionReminderEnum: string
{
    use WithEnumHelpers;

    case FIVE_DAYS = 'five-days';
    case ONE_DAY = 'one-day';

    /**
     * How many days before the deletion date this reminder goes out.
     */
    public function days(): int
    {
        return match ($this) {
            self::FIVE_DAYS => 5,
            self::ONE_DAY => 1,
        };
    }

    /**
     * How the lead time reads in a subject line.
     */
    public function lead(): string
    {
        return match ($this) {
            self::FIVE_DAYS => 'in 5 days',
            self::ONE_DAY => 'tomorrow',
        };
    }

    /**
     * Longest lead time first, so an account that has been silent through both
     * windows is warned about the nearer date rather than the further one.
     *
     * @return array<int, self>
     */
    public static function byLeadTime(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $first, self $second) => $second->days() <=> $first->days());

        return $cases;
    }
}
