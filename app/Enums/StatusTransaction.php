<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusTransaction: int
{
    use WithEnumHelpers;

    case FAILED = 0;
    case CONFIRMED = 1;

    /** Awaiting an administrator: manual review, evidence check, completion. */
    case QUEUED = 2;

    /** Handed to a gateway and awaiting its callback. */
    case PROCESSING = 3;

    case CANCELLED = 4;

    /**
     * The money moved but writing the ledger row afterwards failed. The gap has to
     * be visible rather than silently swallowed — a reconciliation screen exists
     * precisely for rows left in this state.
     */
    case CONFIRMED_BUT_NOT_LOGGED = 5;

    case REFUNDED = 6;
    case REJECTED = 7;

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isConfirmed(): bool
    {
        return $this === self::CONFIRMED;
    }

    public function isQueued(): bool
    {
        return $this === self::QUEUED;
    }

    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function isConfirmedButNotLogged(): bool
    {
        return $this === self::CONFIRMED_BUT_NOT_LOGGED;
    }

    public function isRefunded(): bool
    {
        return $this === self::REFUNDED;
    }

    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }

    /**
     * Reads after "Your transaction …" wherever a result is announced, so the same
     * sentence works in a toast, an email and a receipt.
     */
    public function endMessage(): string
    {
        return match ($this) {
            self::FAILED => 'has failed.',
            self::CONFIRMED => 'was successful.',
            self::QUEUED => 'is queued for review. Please check back later for the final status.',
            self::PROCESSING => 'is being processed. Please check back later for the final status.',
            self::CANCELLED => 'was cancelled.',
            self::CONFIRMED_BUT_NOT_LOGGED => 'was successful but failed to log. Please contact support.',
            self::REFUNDED => 'was refunded.',
            self::REJECTED => 'was rejected.',
        };
    }
}
