<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusEmailCampaign: int
{
    use WithEnumHelpers;

    case DRAFT = 0;
    case SCHEDULED = 1;

    // Recipient rows have been created and the scheduled command is working through
    // them. A campaign sits here — not SENT — until nothing is left PENDING/QUEUED.
    case SENDING = 2;
    case SENT = 3;
    case FAILED = 4;

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isScheduled(): bool
    {
        return $this === self::SCHEDULED;
    }

    public function isSending(): bool
    {
        return $this === self::SENDING;
    }

    public function isSent(): bool
    {
        return $this === self::SENT;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    /**
     * Whether the builder still lets the admin change blocks, recipients, or settings.
     * Once sending has started, the campaign is a record of what went out.
     */
    public function isEditable(): bool
    {
        return $this->isDraft() || $this->isScheduled();
    }
}
