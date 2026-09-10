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
    case USER_TYPE_CHANGE = 'user.type-change';
    case USER_ROLE_ASSIGN = 'user-role.assign';
    case USER_ROLE_CLEAR = 'user-role.clear';

    // Roles. A role is a row an administrator creates, so its lifecycle is logged
    // like any other record — but separately from the gate map it carries, because
    // renaming a role and widening one are not the same event.
    case ROLE_CREATE = 'role.create';
    case ROLE_UPDATE = 'role.update';
    case ROLE_DELETE = 'role.delete';

    // Gates. Widening what an administrator can reach is the change you go looking
    // for after something was touched that should not have been, so it gets its own
    // cases rather than sitting under a general USER_UPDATE.
    case ROLE_GATES_UPDATE = 'role.gates-update';
    case ADMIN_GATES_UPDATE = 'admin.gates-update';
    case ADMIN_GATES_RESET = 'admin.gates-reset';

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

    // Legal pages. Publishing is its own case rather than an update: it is the
    // moment a version becomes the text people are held to, and an audit trail
    // that cannot tell the two apart is not worth reading.
    case POLICY_CREATE = 'policy.create';
    case POLICY_UPDATE = 'policy.update';
    case POLICY_PUBLISH = 'policy.publish';

    case FAQ_CREATE = 'faq.create';
    case FAQ_UPDATE = 'faq.update';
    case FAQ_DELETE = 'faq.delete';

    // Two-factor authentication. Enabling and disabling are separate cases on
    // purpose: "somebody turned the second factor off" is the line you go looking
    // for after an account is taken over, and it must not be buried under UPDATE.
    case TWO_FACTOR_ENABLE = 'two-factor.enable';
    case TWO_FACTOR_DISABLE = 'two-factor.disable';
    case TWO_FACTOR_RECOVERY_REGENERATE = 'two-factor.recovery-regenerate';
    case TWO_FACTOR_RECOVERY_USED = 'two-factor.recovery-used';

    // Connected accounts
    case SOCIAL_ACCOUNT_LINK = 'social-account.link';
    case SOCIAL_ACCOUNT_UNLINK = 'social-account.unlink';

    // Image library
    case IMAGE_UPLOAD = 'image.upload';
    case IMAGE_UPDATE = 'image.update';
    case IMAGE_DELETE = 'image.delete';
    case IMAGE_FOLDER_CREATE = 'image-folder.create';
    case IMAGE_FOLDER_UPDATE = 'image-folder.update';
    case IMAGE_FOLDER_DELETE = 'image-folder.delete';

    // Video library. ADD rather than UPLOAD: nothing is uploaded, a video row is
    // a reference to somebody else's file on somebody else's host.
    case VIDEO_ADD = 'video.add';
    case VIDEO_UPDATE = 'video.update';
    case VIDEO_DELETE = 'video.delete';
    case VIDEO_FOLDER_CREATE = 'video-folder.create';
    case VIDEO_FOLDER_UPDATE = 'video-folder.update';
    case VIDEO_FOLDER_DELETE = 'video-folder.delete';

    // Blog
    case POST_CREATE = 'post.create';
    case POST_UPDATE = 'post.update';
    case POST_PUBLISH = 'post.publish';
    case POST_DELETE = 'post.delete';
    case CATEGORY_CREATE = 'category.create';
    case CATEGORY_UPDATE = 'category.update';
    case CATEGORY_DELETE = 'category.delete';
    case TAG_CREATE = 'tag.create';
    case TAG_UPDATE = 'tag.update';
    case TAG_DELETE = 'tag.delete';

    // Notification types
    case NOTIFICATION_TYPE_CREATE = 'notification-type.create';
    case NOTIFICATION_TYPE_UPDATE = 'notification-type.update';
    case NOTIFICATION_TYPE_DELETE = 'notification-type.delete';

    // Money. Every one of these is an administrator moving somebody else's money
    // and each needs to be individually answerable for.
    case TRANSACTION_CREATE = 'transaction.create';
    case TRANSACTION_CONFIRM = 'transaction.confirm';
    case TRANSACTION_REJECT = 'transaction.reject';
    case TRANSACTION_REFUND = 'transaction.refund';

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
            self::USER_CREATE,
            self::POLICY_CREATE,
            self::FAQ_CREATE,
            self::IMAGE_FOLDER_CREATE,
            self::VIDEO_FOLDER_CREATE,
            self::POST_CREATE,
            self::CATEGORY_CREATE,
            self::ROLE_CREATE,
            self::TAG_CREATE,
            self::NOTIFICATION_TYPE_CREATE,
            self::TRANSACTION_CREATE => 'Created new ',

            // Update
            self::UPDATE,
            self::USER_UPDATE,
            self::CONFIG_UPDATE,
            self::POLICY_UPDATE,
            self::FAQ_UPDATE,
            self::IMAGE_UPDATE,
            self::IMAGE_FOLDER_UPDATE,
            self::VIDEO_UPDATE,
            self::VIDEO_FOLDER_UPDATE,
            self::POST_UPDATE,
            self::CATEGORY_UPDATE,
            self::TAG_UPDATE,
            self::NOTIFICATION_TYPE_UPDATE,
            self::ROLE_UPDATE,
            self::ROLE_GATES_UPDATE,
            self::ADMIN_GATES_UPDATE => 'Updated ',

            // Reset rather than updated: the override is gone and the account is
            // back on its role, which is a different fact about the account.
            self::ADMIN_GATES_RESET => 'Reset ',

            // Delete
            self::DELETE,
            self::ROLE_DELETE,
            self::USER_DELETE,
            self::FAQ_DELETE,
            self::IMAGE_DELETE,
            self::IMAGE_FOLDER_DELETE,
            self::VIDEO_DELETE,
            self::VIDEO_FOLDER_DELETE,
            self::POST_DELETE,
            self::CATEGORY_DELETE,
            self::TAG_DELETE,
            self::NOTIFICATION_TYPE_DELETE => 'Deleted ',

            // Uploads
            self::IMAGE_UPLOAD => 'Uploaded ',

            // Video library. Nothing is uploaded, so the verb is not 'Uploaded'.
            self::VIDEO_ADD => 'Added ',

            // Legal
            self::POLICY_PUBLISH => 'Published ',
            self::POST_PUBLISH => 'Published ',

            // Connected accounts
            self::SOCIAL_ACCOUNT_LINK => 'Connected ',
            self::SOCIAL_ACCOUNT_UNLINK => 'Disconnected ',

            // Money
            self::TRANSACTION_CONFIRM => 'Confirmed ',
            self::TRANSACTION_REJECT => 'Rejected ',
            self::TRANSACTION_REFUND => 'Refunded ',

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

            self::TWO_FACTOR_ENABLE => 'Turned on two-factor authentication.',
            self::TWO_FACTOR_DISABLE => 'Turned off two-factor authentication.',
            self::TWO_FACTOR_RECOVERY_REGENERATE => 'Generated a new set of recovery codes.',
            self::TWO_FACTOR_RECOVERY_USED => 'Signed in using a recovery code.',

            default => '',
        };
    }
}
