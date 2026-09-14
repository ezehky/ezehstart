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

    // The newsletter is a public opt-in, so it is offered to all visitors and not
    public function forAll()
    {
        return \in_array(
            $this,
            [
                self::EMAIL,
                self::ANNOUNCEMENTS,
                self::SECURITY,
            ], true);
    }

    // The other two types are only offered to logged-in users, so they are not
    public function forLoggedInUsers()
    {
        return \in_array(
            $this,
            [
                self::EMAIL,
                self::SECURITY,
            ], true);
    }
}
