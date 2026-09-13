<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusUser;
use App\Mail\AccountDeletionCancelledEmail;
use App\Mail\AccountDeletionScheduledEmail;
use App\Models\Image;
use App\Models\ImageUsage;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoUsage;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Account deletion, which is a grace period rather than an event.
 *
 * Asking to be deleted schedules the account: the status moves to
 * PENDING_DELETION and deletion_scheduled_at records the date the sweep may act
 * on. Nothing is destroyed until that date passes, and cancel() puts everything
 * back — which is the whole point of the delay.
 *
 * What happens at the end of the window depends on two things: whether the
 * account has history worth keeping, and the anonymous-after-deletion switch.
 * See finalize().
 */
#[Singleton]
class AccountDeletionService
{
    /**
     * The grace period used when the site has never saved one.
     */
    public const DEFAULT_GRACE_DAYS = 30;

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // CONFIGURATION

    /**
     * Whether members may delete their own account at all.
     */
    public function isEnabled(): bool
    {
        return (bool) kSiteFlag('user', 'account-deletion', false);
    }

    /**
     * How long an account sits in the grace period, clamped to the same bounds
     * the configuration screen enforces so a hand-edited JSON file cannot set a
     * window of zero — which would delete on the next tick.
     */
    public function graceDays(): int
    {
        $days = (int) kSiteFlag('user', 'account-deletion-days', self::DEFAULT_GRACE_DAYS);

        return max(1, min(365, $days ?: self::DEFAULT_GRACE_DAYS));
    }

    /**
     * Whether an account with history is anonymized at the end of the window.
     *
     * Off means the opposite, and it means it literally: everything the account
     * owns is removed with it. Read through kSiteFlag rather than kSiteConfig —
     * a switch deliberately turned off must not read back as its default.
     */
    public function anonymizesAfterDeletion(): bool
    {
        return (bool) kSiteFlag('user', 'anonymous-after-deletion', true);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // THE GRACE PERIOD

    /**
     * Put the account into its grace period and tell the account holder when it
     * ends. Returns the date the sweep will act on.
     */
    public function schedule(User $user): CarbonInterface
    {
        $scheduledAt = now()->addDays($this->graceDays());

        DB::transaction(function () use ($user, $scheduledAt) {
            $user->forceFill([
                'status' => StatusUser::PENDING_DELETION,
                'deletion_requested_at' => now(),
                'deletion_scheduled_at' => $scheduledAt,
            ])->save();

            // A previous request that was cancelled leaves its claims behind, and
            // an account that changes its mind twice has to be warned properly the
            // second time round.
            $user->deletionReminders()->delete();

            app(ActivityLogService::class)->logActivity(ActivityActionEnum::ACCOUNT_DELETE_SCHEDULE);
        });

        Mail::to($user->email)->queue(new AccountDeletionScheduledEmail($user, $scheduledAt));

        return $scheduledAt;
    }

    /**
     * Change of mind. Everything about the request goes, including the claims,
     * so the account is left exactly as it was before it asked.
     */
    public function cancel(User $user, bool $notify = true): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill([
                'status' => StatusUser::ACTIVE,
                'deletion_requested_at' => null,
                'deletion_scheduled_at' => null,
            ])->save();

            $user->deletionReminders()->delete();

            app(ActivityLogService::class)->logActivity(ActivityActionEnum::ACCOUNT_DELETE_CANCEL);
        });

        if ($notify) {
            Mail::to($user->email)->queue(new AccountDeletionCancelledEmail($user));
        }
    }

    /**
     * Whether the account is inside a grace period that has not run out yet.
     */
    public function isPending(User $user): bool
    {
        return $user->status->isPendingDeletion() && $user->deletion_scheduled_at !== null;
    }

    /**
     * Whole days left before the account goes, floored at zero — a window that
     * has already passed reads as today rather than as a negative number.
     */
    public function daysRemaining(User $user): int
    {
        if (! $this->isPending($user)) {
            return 0;
        }

        $days = now()->startOfDay()->diffInDays($user->deletion_scheduled_at->startOfDay(), false);

        return max(0, (int) $days);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // THE END OF THE WINDOW

    /**
     * Carry out the deletion the account asked for, and report which of the two
     * things happened so the caller can say so.
     *
     * An account with nothing pointing at it is always removed outright — there
     * is no history to protect, and leaving an anonymized shell behind would be
     * worse for the person who asked to be forgotten. Only an account that does
     * have history consults the switch.
     */
    public function finalize(User $user): ActivityActionEnum
    {
        if (! $this->hasSignificantActivity($user) || ! $this->anonymizesAfterDeletion()) {
            $this->hardDelete($user);

            return ActivityActionEnum::ACCOUNT_DELETE;
        }

        $this->anonymize($user);

        return ActivityActionEnum::ACCOUNT_ANONYMIZE;
    }

    /**
     * Whether the user has history worth preserving — if so we anonymize instead of
     * hard-deleting, so records that point at them stay resolvable.
     *
     * The starter only knows about the audit trail and the ledger. Add each relation
     * that counts as history the moment your project grows one: orders, enrolments,
     * referrals.
     */
    public function hasSignificantActivity(User $user): bool
    {
        return $user->activityLogs()->exists() || $user->transactions()->exists();
    }

    /**
     * Strip personal information but keep the account row (soft-deleted) so
     * related history remains intact and resolvable.
     */
    public function anonymize(User $user): void
    {
        DB::transaction(function () use ($user) {
            kDeleteFile($user->avatar);

            $user->forceFill([
                'name' => 'Anonymous User',
                'email' => "deleted-user-{$user->id}@anonymized.local",
                'phone_number' => null,
                'avatar' => null,
                'password' => null,
                'status' => StatusUser::DELETED,
                'deletion_requested_at' => null,
                'deletion_scheduled_at' => null,
            ])->save();

            $user->userProfile()->update([
                'bio' => null,
                'gender' => null,
                'city' => null,
                'postal_code' => null,
            ]);

            $user->deletionReminders()->delete();

            app(ActivityLogService::class)->logActivity(ActivityActionEnum::ACCOUNT_ANONYMIZE);

            $user->delete();
        });
    }

    /**
     * Permanently remove the user and everything that belongs to them.
     *
     * Most of the tree goes with the row: every table that names a user_id
     * cascades. What does not cascade is handled here — the files behind the
     * image library, the usage rows that would otherwise refuse the delete
     * outright, and the two tables (notifications and sessions) that point at a
     * user without a foreign key to enforce it.
     */
    public function hardDelete(User $user, ActivityActionEnum $action = ActivityActionEnum::ACCOUNT_DELETE, ?string $description = null): void
    {
        DB::transaction(function () use ($user, $action, $description) {
            kDeleteFile($user->avatar);

            $this->purgeLibraries($user);

            // Written before the row goes, and cascaded away with it. It is here
            // for the case where an administrator is the one signed in — then the
            // log belongs to them and survives. The action is a parameter because
            // the sweep fulfilling a request somebody made and an administrator
            // purging a shell they chose not to keep are not the same event.
            app(ActivityLogService::class)->logActivity(
                $action,
                $description,
                model: $user,
            );

            DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id)
                ->delete();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->forceDelete();
        });
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * Take this account's images and videos with it.
     *
     * The usage rows have to go first: image_usages restricts on delete on
     * purpose, so a library file cannot be pulled out from under a post that is
     * showing it. That guard is right for the library screen and wrong here —
     * the owner is going, so anything still pointing at their files is released
     * rather than allowed to veto the deletion. A post cover that pointed at one
     * of these images falls back to null, which is what posts.image_id was made
     * nullable for.
     *
     * The files are removed after the rows, and only for paths that actually
     * came back, so a row already missing its file is not a failure.
     */
    protected function purgeLibraries(User $user): void
    {
        $images = Image::query()->where('user_id', $user->id)->pluck('file_path', 'id');

        if ($images->isNotEmpty()) {
            ImageUsage::query()->whereIn('image_id', $images->keys())->delete();
            Image::query()->whereIn('id', $images->keys())->delete();

            $images->filter()->each(fn (string $path) => kDeleteFile($path));
        }

        // Videos are references rather than files, so there is nothing on disk to
        // clean up — only the usage rows standing in the way of the cascade.
        $videos = Video::query()->where('user_id', $user->id)->pluck('id');

        if ($videos->isNotEmpty()) {
            VideoUsage::query()->whereIn('video_id', $videos)->delete();
            Video::query()->whereIn('id', $videos)->delete();
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // AFTER THE SWEEP

    /**
     * Every anonymized account, newest first.
     *
     * These are soft-deleted rows, so nothing else in the admin surface can see them —
     * the default scope hides them from the users listing, the metrics and the search.
     * That is the whole reason the deleted-accounts screen exists: without it an
     * anonymized shell is a row nobody can account for and nobody can finish removing.
     *
     * @return Builder<User>
     */
    public function trashedQuery(): Builder
    {
        return User::query()->onlyTrashed();
    }

    /**
     * Put an anonymized account row back.
     *
     * This restores the **record**, not the person. anonymize() already overwrote the
     * name, the email and the password before the row was trashed, so there is nothing
     * here that hands anybody their account back — the status stays DELETED and the
     * password stays null. What it buys is history that resolves again: a transaction or
     * an audit entry pointing at a trashed user renders as nobody at all.
     */
    public function restore(User $user): void
    {
        if (! $user->trashed()) {
            return;
        }

        $user->restore();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::RESTORE,
            " account record: {$user->email}",
            model: $user,
        );
    }

    /**
     * Finish the erasure an anonymize() only went halfway through.
     *
     * Anonymizing is the compromise struck for accounts with history. Purging is the
     * decision to stop keeping even that, and it takes the history with it — so it is
     * deliberately a separate, full-access action rather than something the nightly
     * sweep ever does on its own.
     */
    public function purge(User $user): void
    {
        // Named rather than left to the default description: the defaults here are
        // written in the account holder's voice ("their account"), and this is an
        // administrator acting on somebody else's record.
        $this->hardDelete($user, ActivityActionEnum::FORCE_DELETE, " account: {$user->email}");
    }

    /**
     * Why this account cannot be purged, or null when it can.
     *
     * A live account is not something this screen removes: it has its own deletion
     * flow, with a grace period the account holder controls, and going around that
     * from here would be the one delete nobody consented to.
     */
    public function purgeBlockedReason(User $user): ?string
    {
        if (! $user->trashed()) {
            return 'Only an account that has already been deleted can be purged from here.';
        }

        return null;
    }
}
