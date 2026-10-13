<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Where a received webhook is in its handling.
 *
 * A webhook is written before it is worked, so a call that arrives while the
 * queue is down is still on file, and can be retried from the admin screen or
 * the payments:retry-webhooks command once the worker is back.
 */
enum StatusPaymentWebhook: int
{
    use WithEnumHelpers;

    /** Stored and queued, not yet worked. */
    case PENDING = 0;

    case PROCESSED = 1;

    /** Every attempt failed. Retryable by hand. */
    case FAILED = 2;

    /**
     * Signed and stored, but about nothing this application acts on — an event
     * type no handler covers, or a reference that matches no transaction.
     */
    case IGNORED = 3;

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isProcessed(): bool
    {
        return $this === self::PROCESSED;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isIgnored(): bool
    {
        return $this === self::IGNORED;
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'amber',
            self::PROCESSED => 'green',
            self::FAILED => 'red',
            self::IGNORED => 'zinc',
        };
    }
}
