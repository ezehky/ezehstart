<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum ActivityActionEnum: string
{
    use WithEnumHelpers;

    // Generic CRUD — reach for these before adding a subject-specific case.
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case RESTORE = 'restore';
    case FORCE_DELETE = 'force_delete';
    case IMPORT = 'import';
    case EXPORT = 'export';
    case UPLOAD = 'upload';
    case DOWNLOAD = 'download';
    case VIEW = 'view';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    // User cases
    case USER_CREATE = 'user.create';
    case USER_UPDATE = 'user.update';
    case USER_DELETE = 'user.delete';
    case USER_STATUS_CHANGE = 'user.status-change';
    case USER_ROLE_GRANT = 'user-role.grant';
    case USER_ROLE_REVOKE = 'user-role.revoke';
    case USER_ROLE_SWITCH = 'user-role.switch';

    // Personal
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case REGISTER = 'register';
    case PASSWORD_CHANGE = 'password-change';
    case PASSWORD_RESET_REQUEST = 'password-reset-request';
    case EMAIL_CHANGE = 'email-change';
    case SESSION_LOGOUT_OTHERS = 'session.logout-others';
    case SETTINGS_UPDATE = 'settings.update';
    case ACCOUNT_ANONYMIZE = 'account.anonymize';
    case ACCOUNT_DELETE = 'account.delete';

    // Admin Operation cases
    case PASSWORD_RESET = 'password-reset';

    // Site configuration
    case CONFIG_UPDATE = 'config.update';

    // Methods

    /**
     * The opening clause of a generated activity description. The subject the
     * action was performed on is appended by ActivityLogService.
     */
    public function startDescription(): string
    {
        return match ($this) {
            // Create
            self::CREATE,
            self::USER_CREATE => 'Created new ',

            // Update
            self::UPDATE,
            self::USER_UPDATE,
            self::CONFIG_UPDATE => 'Updated ',

            // Delete
            self::DELETE,
            self::USER_DELETE => 'Deleted ',

            // Personal
            self::PASSWORD_RESET_REQUEST => 'Requested a password reset for ',
            self::PASSWORD_CHANGE => 'Changed password for ',
            self::EMAIL_CHANGE => 'Changed email address to ',

            // Default
            default => '',
        };
    }

    /**
     * A complete description for actions that have no subject to append.
     */
    public function defaultDescription(): string
    {
        return match ($this) {
            self::LOGIN => 'Logged into the system.',
            self::LOGOUT => 'Logged out of the system.',
            self::REGISTER => 'Registered a new account.',
            self::SESSION_LOGOUT_OTHERS => 'Logged out of other active sessions.',
            self::SETTINGS_UPDATE => 'Updated their account preferences.',
            self::ACCOUNT_ANONYMIZE => 'Deleted their account (data anonymized).',
            self::ACCOUNT_DELETE => 'Permanently deleted their account.',

            default => '',
        };
    }
}
