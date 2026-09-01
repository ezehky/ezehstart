<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The notification channels a user can opt in and out of, one row per case in
 * notification_subscriptions.
 */
enum NotificationTypeEnum: string
{
    use WithEnumHelpers;

    case EMAIL = 'email';
    case ANNOUNCEMENTS = 'announcements';
    case SECURITY = 'security';

    public function description(): string
    {
        return match ($this) {
            self::EMAIL => 'Receipts, announcements, and account updates.',
            self::ANNOUNCEMENTS => 'Product news and occasional announcements.',
            self::SECURITY => 'Sign-in alerts and changes to your credentials.',
        };
    }
}
