<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusEmailCampaignRecipient: int
{
    use WithEnumHelpers;

    case PENDING = 0;
    case QUEUED = 1;
    case SENT = 2;
    case FAILED = 3;
    case BOUNCED = 4;

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isQueued(): bool
    {
        return $this === self::QUEUED;
    }

    public function isSent(): bool
    {
        return $this === self::SENT;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isBounced(): bool
    {
        return $this === self::BOUNCED;
    }
}
