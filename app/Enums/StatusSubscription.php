<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Where a subscription stands.
 *
 * CANCELLED is "will not renew", not "over": the period already paid for still
 * runs to current_period_ends_at. EXPIRED is over.
 */
enum StatusSubscription: int
{
    use WithEnumHelpers;

    /** Checkout opened, first payment not yet confirmed. */
    case PENDING = 0;

    case ACTIVE = 1;

    /** A renewal failed; the vendor is retrying. Access is the project's call. */
    case PAST_DUE = 2;

    case CANCELLED = 3;
    case EXPIRED = 4;

    /**
     * A free trial the kit runs itself: no vendor, no card, nothing billed.
     * It ends at current_period_ends_at unless the member subscribes first.
     */
    case TRIALING = 5;

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isPastDue(): bool
    {
        return $this === self::PAST_DUE;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this === self::EXPIRED;
    }

    public function isTrialing(): bool
    {
        return $this === self::TRIALING;
    }

    public function color(): string
    {
        return match ($this) {
            self::TRIALING => 'sky',
            self::PENDING => 'zinc',
            self::ACTIVE => 'green',
            self::PAST_DUE => 'amber',
            self::CANCELLED => 'orange',
            self::EXPIRED => 'red',
        };
    }
}
