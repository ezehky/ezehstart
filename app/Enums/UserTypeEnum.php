<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What kind of account this is, and therefore which workspace it signs in to.
 *
 * Types are fixed in code because a workspace is: adding one means a middleware, a
 * routes file and a group in bootstrap/app.php, none of which an administrator can
 * conjure from a form. Roles are the opposite — they are rows, created from the
 * dashboard, and only an ADMIN account carries one. See roles.md.
 *
 * Every branch a type decides lives on the case itself rather than in a match table
 * somewhere else, so adding a third workspace fails to compile here and nowhere far
 * away from here.
 */
enum UserTypeEnum: string
{
    use WithEnumHelpers;

    case USER = 'user';
    case ADMIN = 'admin';

    public function isUser(): bool
    {
        return $this === self::USER;
    }

    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Does this type sign in to a gated workspace, and so need a role?
     *
     * Only the admin workspace is gated. A member reaches their own account and
     * nothing else, so a role on one would grant nothing and mean nothing.
     */
    public function carriesRole(): bool
    {
        return $this === self::ADMIN;
    }

    public function dashboardRoute(): string
    {
        return match ($this) {
            self::ADMIN => route('admin.dashboard'),
            self::USER => route('user.dashboard'),
        };
    }

    public function dashboardLabel(): string
    {
        return match ($this) {
            self::ADMIN => 'Administration',
            self::USER => 'Dashboard',
        };
    }

    /**
     * The admin listing that shows accounts of this type.
     */
    public function listingRoute(): string
    {
        return match ($this) {
            self::ADMIN => route('admin.admins'),
            self::USER => route('admin.members'),
        };
    }
}
