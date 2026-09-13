<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The subject of a database notification, stored as the notification title.
 *
 * A topic decides how the entry reads in the bell menu — its heading, icon and
 * colour — so the message itself only has to carry the detail. Topics prefixed
 * with "admin." are raised for the admin team rather than the account the
 * action belongs to.
 *
 * Add a case per notification your project raises, and give it a label, an icon
 * and a colour. Every match arm below is exhaustive on purpose: a new case will
 * fail loudly until it is described.
 */
enum NotificationTopicEnum: string
{
    use WithEnumHelpers;

    // Account holder topics
    case WELCOME = 'welcome';
    case ACCOUNT_UPDATED = 'account-updated';
    case SECURITY_ALERT = 'security-alert';
    case NEW_POST = 'new-post';
    case UPLOAD_MODIFIED = 'upload-modified';

    // Admin topics
    case ADMIN_NEW_ACCOUNT = 'admin.new-account';
    case ADMIN_UNASSIGNED_ACCOUNT = 'admin.unassigned-account';

    /**
     * Resolve a stored notification title, which may predate this enum or come
     * from a topic that has since been removed.
     */
    public static function fromTitle(?string $title): ?self
    {
        return $title ? self::tryFrom($title) : null;
    }

    /**
     * Whether this topic is addressed to the admin team.
     */
    public function isAdminTopic(): bool
    {
        return str_starts_with($this->value, 'admin.');
    }

    public function label(): string
    {
        return match ($this) {
            self::WELCOME => 'Welcome aboard',
            self::ACCOUNT_UPDATED => 'Account updated',
            self::SECURITY_ALERT => 'Security alert',
            self::NEW_POST => 'New post',
            self::UPLOAD_MODIFIED => 'Your uploads changed',
            self::ADMIN_NEW_ACCOUNT => 'New account',
            self::ADMIN_UNASSIGNED_ACCOUNT => 'Admins without a role',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WELCOME => 'sparkles',
            self::ACCOUNT_UPDATED => 'user-circle',
            self::SECURITY_ALERT => 'shield-exclamation',
            self::NEW_POST => 'newspaper',
            self::UPLOAD_MODIFIED => 'photo',
            self::ADMIN_NEW_ACCOUNT => 'user-plus',
            self::ADMIN_UNASSIGNED_ACCOUNT => 'exclamation-triangle',
        };
    }

    /**
     * The palette the bell menu tints this entry with.
     */
    public function color(): string
    {
        return match ($this) {
            self::WELCOME, self::ADMIN_NEW_ACCOUNT => 'green',
            self::SECURITY_ALERT => 'red',
            self::ADMIN_UNASSIGNED_ACCOUNT => 'amber',
            self::ACCOUNT_UPDATED, self::NEW_POST => 'blue',
            self::UPLOAD_MODIFIED => 'amber',
        };
    }
}
