<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * "We missed you" for members who have gone quiet. Another is only sent once
 * the member has been seen again since the last, so an account that never
 * comes back is mailed once rather than on every run forever.
 */
class SendInactivityReminderCommand extends Command
{
    protected $signature = 'account:send-inactivity-reminders
                            {--dry-run : Report what would be sent without mailing anything}';

    protected $description = 'Email a "we missed you" reminder to members inactive past the configured number of days';

    public function handle(): int
    {
        $service = app(AccountLifecycleService::class);

        if (! $service->sendsInactivityReminders()) {
            $this->info('Inactivity reminders are off. Nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        $service->dueInactivityReminder()->orderBy('id')->get()->each(function (User $user) use ($service, $dryRun, &$sent) {
            if ($dryRun) {
                $sent++;
                $this->line(sprintf('  <fg=green>remind</> · #%d %s', $user->id, $user->email));

                return;
            }

            try {
                if ($service->remindInactive($user)) {
                    $sent++;
                    $this->line(sprintf('  <fg=green>reminded</> · #%d %s', $user->id, $user->email));
                }
            } catch (\Throwable $exception) {
                // One bad address must not stop the rest of the run.
                Log::channel('ezeh')->error('Inactivity reminder failed to queue', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->line(sprintf('  <fg=red>failed</> · #%d %s', $user->id, $user->email));
            }
        });

        $this->info(sprintf('%s %d inactivity reminder(s).', $dryRun ? 'Would send' : 'Sent', $sent));

        return self::SUCCESS;
    }
}
