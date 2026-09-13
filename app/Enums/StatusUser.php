<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusUser: int
{
    use WithEnumHelpers;

    case ACTIVE = 1;
    case SUSPENDED = 0;

    /**
     * The account holder asked for the account to go. It is still signed in and
     * still theirs — the grace period exists so somebody who changed their mind
     * has a window to say so, and only the scheduler ends it.
     */
    case PENDING_DELETION = 2;

    /**
     * The grace period ran out and the account was anonymized rather than
     * removed, because it had history other rows still point at. Nothing signs
     * in again from here.
     */
    case DELETED = 3;

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this === self::SUSPENDED;
    }

    public function isPendingDeletion(): bool
    {
        return $this === self::PENDING_DELETION;
    }

    public function isDeleted(): bool
    {
        return $this === self::DELETED;
    }

    /**
     * Whether the account is somewhere in the deletion flow. The admin screens
     * ask this before offering a status control: neither of these two states is
     * one an Active/Suspended switch can express, and saving over them would
     * quietly cancel a deletion or reactivate an account that is gone.
     */
    public function inDeletionFlow(): bool
    {
        return $this->isPendingDeletion() || $this->isDeleted();
    }

    public function message(): ?string
    {
        return match ($this) {
            // self::BLOCKED => 'Your account is blocked!',
            self::SUSPENDED => 'Account is suspended.',
            self::DELETED => 'This account has been deleted.',
            // self::USER_LOCKED => 'You locked your account.',
            default => null
        };
    }
}
