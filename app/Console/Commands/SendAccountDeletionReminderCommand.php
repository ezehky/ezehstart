<?php

namespace App\Console\Commands;

use App\Enums\DeletionReminderEnum;
use App\Mail\AccountDeletionReminderEmail;
use App\Models\User;
use App\Models\UserDeletionReminder;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAccountDeletionReminderCommand extends Command
{
    protected $signature = 'account:send-deletion-reminders
                            {--reminder=* : Only send these lead times (five-days, one-day)}
                            {--user=* : Send for these user ids, ignoring the time window}
                            {--tolerance=1440 : How many minutes wide the send window is}
                            {--force : Send again even if the reminder was already recorded}
                            {--dry-run : Report what would be sent without mailing or recording anything}';

    protected $description = 'Warn accounts 5 days and 1 day before their scheduled deletion date';

    protected bool $dryRun = false;

    protected bool $force = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->force = (bool) $this->option('force');

        $tolerance = max(1, (int) $this->option('tolerance'));
        $now = now();
        $sent = 0;

        foreach ($this->reminders() as $reminder) {
            $this->accountsFor($reminder, $now, $tolerance)->each(function (User $user) use ($reminder, &$sent) {
                if (! $this->claim($user, $reminder)) {
                    return;
                }

                if (! $this->deliver($user, $reminder)) {
                    return;
                }

                $sent++;

                $this->line(sprintf(
                    '  <fg=green>%s</> · #%d %s · deleted %s',
                    $reminder->label(),
                    $user->id,
                    $user->email,
                    $user->deletion_scheduled_at?->format('M d, Y'),
                ));
            });
        }

        $this->info(sprintf(
            '%s %d reminder(s).',
            $this->dryRun ? 'Would send' : 'Sent',
            $sent,
        ));

        return self::SUCCESS;
    }

    /**
     * The lead times this run covers, longest first.
     *
     * @return array<int, DeletionReminderEnum>
     */
    protected function reminders(): array
    {
        $only = (array) $this->option('reminder');

        return array_values(array_filter(
            DeletionReminderEnum::byLeadTime(),
            fn (DeletionReminderEnum $reminder) => ! $only || \in_array($reminder->value, $only, true),
        ));
    }

    /**
     * Accounts due this reminder: still pending, and with a deletion date inside
     * the window that closes `tolerance` minutes after the lead time is reached.
     *
     * The window is closed at both ends on purpose. A scheduler that has been
     * down for a week must not come back up and tell somebody their account goes
     * in five days when it actually goes tomorrow.
     *
     * @return Collection<int, User>
     */
    protected function accountsFor(DeletionReminderEnum $reminder, CarbonInterface $now, int $tolerance): Collection
    {
        $ids = (array) $this->option('user');

        $windowEnd = $now->copy()->addDays($reminder->days());
        $windowStart = $windowEnd->copy()->subMinutes($tolerance);

        return User::query()
            ->pendingDeletion()
            ->when(
                $ids,
                fn ($query) => $query->whereIn('id', $ids),
                fn ($query) => $query->whereBetween('deletion_scheduled_at', [$windowStart, $windowEnd]),
            )
            ->orderBy('deletion_scheduled_at')
            ->get();
    }

    /**
     * Reserve this reminder before mailing. The unique index on
     * (user_id, reminder) means two overlapping runs cannot both win, so nobody
     * is told twice that their account is about to go.
     */
    protected function claim(User $user, DeletionReminderEnum $reminder): bool
    {
        if ($this->dryRun) {
            return $this->force || ! $user->deletionReminders()->where('reminder', $reminder)->exists();
        }

        if ($this->force) {
            UserDeletionReminder::updateOrCreate(
                ['user_id' => $user->id, 'reminder' => $reminder],
                ['sent_at' => now()],
            );

            return true;
        }

        return UserDeletionReminder::insertOrIgnore([
            'user_id' => $user->id,
            'reminder' => $reminder->value,
            'sent_at' => now(),
        ]) > 0;
    }

    /**
     * Queue the warning, reporting rather than throwing when one address fails.
     */
    protected function deliver(User $user, DeletionReminderEnum $reminder): bool
    {
        if ($this->dryRun) {
            return true;
        }

        try {
            Mail::to($user->email)->queue(new AccountDeletionReminderEmail($user, $reminder));

            return true;
        } catch (\Throwable $exception) {
            // One bad address must not stop the rest of the run.
            Log::channel('ezeh')->error('Account deletion reminder failed to queue', [
                'user_id' => $user->id,
                'reminder' => $reminder->value,
                'message' => $exception->getMessage(),
            ]);

            $this->line(sprintf('  <fg=red>failed</> · #%d %s', $user->id, $user->email));

            return false;
        }
    }
}
