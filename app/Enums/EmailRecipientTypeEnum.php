<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * How a campaign's audience is chosen. There is no "segment" concept anywhere in this
 * kit yet, so targeting reuses the notification_preferences mechanism
 * NewsletterService/NotificationSubscriberService already read — PREFERENCES is a real
 * audience, not a placeholder.
 */
enum EmailRecipientTypeEnum: string
{
    use WithEnumHelpers;

    case SPECIFIC = 'specific';
    case ALL_USERS = 'all_users';
    case PREFERENCES = 'preferences';

    public function isSpecific(): bool
    {
        return $this === self::SPECIFIC;
    }

    public function isAllUsers(): bool
    {
        return $this === self::ALL_USERS;
    }

    public function isPreferences(): bool
    {
        return $this === self::PREFERENCES;
    }
}
