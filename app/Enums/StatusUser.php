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

    /**
     * An address that asked for the newsletter and nothing else.
     *
     * It is a users row rather than a table of its own, so that one address is one
     * row whether or not the person behind it ever signs up, and so a send reads
     * the notification_preferences switch every registered account already has
     * instead of a second list that would have to be kept in step with it.
     *
     * There is no account here yet: no password, no verified address, nothing to
     * sign in to. Registering later claims this row rather than being refused for
     * an address that is already taken — see WithAuthWorker::createUser().
     */
    case NEWSLETTER_SUBSCRIBER = 4;

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

    public function isNewsletterSubscriber(): bool
    {
        return $this === self::NEWSLETTER_SUBSCRIBER;
    }

    /**
     * Whether this row is an account at all, as opposed to an address the site is
     * holding for one reason or another.
     *
     * Asked by every listing and count that means "our users", so a newsletter
     * address does not quietly inflate the member numbers, and by the sign-in
     * flows, so one cannot be signed in to.
     */
    public function isRegistered(): bool
    {
        return ! $this->isNewsletterSubscriber();
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
            self::NEWSLETTER_SUBSCRIBER => 'That address is on the newsletter list but has no account yet. Please register.',
            // self::USER_LOCKED => 'You locked your account.',
            default => null
        };
    }

    /**
     * Kept off the status dropdowns and the listing filter.
     *
     * Not a status an administrator assigns: it is what a row is *until* somebody
     * registers, and saving it onto a real account would strip that account of its
     * sign-in without deleting anything.
     */
    protected static function forSelectValuesArg(): array
    {
        return [self::NEWSLETTER_SUBSCRIBER->value];
    }
}
