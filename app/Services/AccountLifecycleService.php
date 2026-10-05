<?php

namespace App\Services;

use App\Enums\StatusUser;
use App\Mail\InactivityReminderEmail;
use App\Mail\UnverifiedAccountWarningEmail;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The two things the app does to an account nobody asked it to: removing one
 * that never verified its address, and nudging one that has gone quiet.
 *
 * Both only ever touch member accounts that are active. An administrator is
 * never swept or nudged, a newsletter row has no account to verify, and an
 * account already in its deletion grace period has its own sweep.
 */
#[Singleton]
class AccountLifecycleService
{
    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // CONFIGURATION

    /**
     * Whether unverified accounts are warned and then removed.
     *
     * Never while verification itself is off. An account registered then has no
     * code to verify with, so every one of them is unverified — the sweep would
     * take the whole member list.
     */
    public function prunesUnverified(): bool
    {
        return (bool) kSiteFlag('email-settings', 'verification', false)
            && (bool) kSiteFlag('email-settings', 'unverified-auto-delete', true);
    }

    /**
     * Days after sign-up that the warning goes out, held to the bounds the
     * configuration screen enforces.
     */
    public function unverifiedNoticeDays(): int
    {
        return max(1, min(30, (int) kSiteFlag('email-settings', 'unverified-notice-days', 2)));
    }

    /**
     * Days after sign-up that the account goes. Always at least a day past the
     * notice, so a hand-edited file cannot have the warning and the delete land
     * on the same run.
     */
    public function unverifiedDeleteDays(): int
    {
        $notice = $this->unverifiedNoticeDays();

        return max($notice + 1, min(60, (int) kSiteFlag('email-settings', 'unverified-delete-days', 3)));
    }

    /**
     * The gap between the warning and the delete, which is what the warning
     * promises the account holder.
     */
    public function unverifiedGraceDays(): int
    {
        return $this->unverifiedDeleteDays() - $this->unverifiedNoticeDays();
    }

    public function sendsInactivityReminders(): bool
    {
        return (bool) kSiteFlag('user', 'inactivity-reminder', true);
    }

    public function inactivityDays(): int
    {
        return max(1, min(365, (int) kSiteFlag('user', 'inactivity-reminder-days', 30)));
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // UNVERIFIED ACCOUNTS

    /**
     * Unverified accounts old enough for the warning that have not had it.
     *
     * @return Builder<User>
     */
    public function dueUnverifiedWarning(): Builder
    {
        return $this->unverifiedQuery()
            ->whereNull('unverified_notice_sent_at')
            ->where('created_at', '<=', now()->subDays($this->unverifiedNoticeDays()));
    }

    /**
     * Unverified accounts warned at least the grace period ago.
     *
     * Counted from the warning rather than from sign-up, so an account that was
     * already old the first time the sweep saw it — one registered while
     * verification was off, say — still gets the whole window it was promised.
     *
     * @return Builder<User>
     */
    public function dueUnverifiedDeletion(): Builder
    {
        return $this->unverifiedQuery()
            ->whereNotNull('unverified_notice_sent_at')
            ->where('unverified_notice_sent_at', '<=', now()->subDays($this->unverifiedGraceDays()));
    }

    /**
     * Claim the warning, then queue it. The claim is a conditional update, so two
     * overlapping runs cannot both mail the same account. Returns false when the
     * other run got there first.
     */
    public function warnUnverified(User $user): bool
    {
        $claimed = User::query()
            ->whereKey($user->id)
            ->whereNull('unverified_notice_sent_at')
            ->update(['unverified_notice_sent_at' => now()]) > 0;

        if ($claimed) {
            Mail::to($user->email)->queue(new UnverifiedAccountWarningEmail($user, $this->unverifiedGraceDays()));
        }

        return $claimed;
    }

    /**
     * Remove an account that never verified, through the same path the deletion
     * sweep uses, so its files and sessions go with it.
     *
     * The audit trail only records a signed-in actor, and a scheduled run has
     * none — so the removal is written to the application log as well. A
     * deletion nobody asked for should leave a trace somewhere.
     */
    public function deleteUnverified(User $user): void
    {
        Log::channel('ezeh')->info('Unverified account removed', [
            'user_id' => $user->id,
            'email' => $user->email,
            'created_at' => $user->created_at?->toIso8601String(),
            'warned_at' => $user->unverified_notice_sent_at?->toIso8601String(),
        ]);

        app(AccountDeletionService::class)->hardDelete($user);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // INACTIVITY

    /**
     * Members not seen for the configured number of days who have not been
     * reminded since they were last seen.
     *
     * An account that has never been seen is left alone: it has nothing to be
     * missed from, and "we missed you" to somebody who never came is spam.
     *
     * @return Builder<User>
     */
    public function dueInactivityReminder(): Builder
    {
        return User::query()
            ->users()
            ->where('status', StatusUser::ACTIVE)
            // Only a proven address while verification is on. An unverified one
            // belongs to the sweep above, and may not be the account holder's.
            ->when(
                (bool) kSiteFlag('email-settings', 'verification', false),
                fn (Builder $query) => $query->whereNotNull('email_verified_at'),
            )
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<=', now()->subDays($this->inactivityDays()))
            ->where(fn (Builder $query) => $query
                ->whereNull('inactivity_reminder_sent_at')
                ->orWhereColumn('inactivity_reminder_sent_at', '<', 'last_seen_at'));
    }

    /**
     * Claim the reminder for this absence, then queue it. Returns false when an
     * overlapping run claimed it first.
     */
    public function remindInactive(User $user): bool
    {
        $claimed = User::query()
            ->whereKey($user->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('inactivity_reminder_sent_at')
                ->orWhereColumn('inactivity_reminder_sent_at', '<', 'last_seen_at'))
            ->update(['inactivity_reminder_sent_at' => now()]) > 0;

        if ($claimed) {
            $daysAway = (int) $user->last_seen_at->diffInDays(now());

            Mail::to($user->email)->queue(new InactivityReminderEmail($user, $daysAway));
        }

        return $claimed;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * Active members with no verified address.
     *
     * An account with ledger rows is never swept, verified or not: money history
     * is not something a schedule gets to erase. It stays for an administrator
     * to decide about.
     *
     * @return Builder<User>
     */
    private function unverifiedQuery(): Builder
    {
        return User::query()
            ->users()
            ->where('status', StatusUser::ACTIVE)
            ->whereNull('email_verified_at')
            ->whereDoesntHave('transactions');
    }
}
