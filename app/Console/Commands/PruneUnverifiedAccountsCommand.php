<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Accounts that never verify their address hold a unique email forever and
 * never become anybody. Each one is warned once the notice window has passed,
 * then removed once the grace period after the warning has.
 */
class PruneUnverifiedAccountsCommand extends Command
{
    protected $signature = 'account:prune-unverified
                            {--dry-run : Report what would happen without mailing or deleting anything}';

    protected $description = 'Warn unverified accounts, then delete the ones still unverified after the grace period';

    protected bool $dryRun = false;

    public function handle(): int
    {
        $service = app(AccountLifecycleService::class);

        if (! $service->prunesUnverified()) {
            $this->info('Unverified account removal is off, or email verification is. Nothing to do.');

            return self::SUCCESS;
        }

        $this->dryRun = (bool) $this->option('dry-run');

        // Deletions first, so an account warned on this run is never also one it
        // deletes — the query for the delete only sees warnings already sent.
        $deleted = $this->deletePending($service);
        $warned = $this->warnPending($service);

        $this->info(sprintf(
            '%s %d warning(s) and %s %d unverified account(s).',
            $this->dryRun ? 'Would send' : 'Sent',
            $warned,
            $this->dryRun ? 'would delete' : 'deleted',
            $deleted,
        ));

        return self::SUCCESS;
    }

    protected function warnPending(AccountLifecycleService $service): int
    {
        $warned = 0;

        $service->dueUnverifiedWarning()->orderBy('id')->get()->each(function (User $user) use ($service, &$warned) {
            if ($this->dryRun) {
                $warned++;
                $this->line(sprintf('  <fg=yellow>warn</> · #%d %s', $user->id, $user->email));

                return;
            }

            try {
                if ($service->warnUnverified($user)) {
                    $warned++;
                    $this->line(sprintf('  <fg=yellow>warned</> · #%d %s', $user->id, $user->email));
                }
            } catch (\Throwable $exception) {
                // One bad address must not stop the rest of the run.
                Log::channel('ezeh')->error('Unverified account warning failed to queue', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->line(sprintf('  <fg=red>failed</> · #%d %s', $user->id, $user->email));
            }
        });

        return $warned;
    }

    protected function deletePending(AccountLifecycleService $service): int
    {
        $deleted = 0;

        // Collected first rather than chunked, because chunking a query while
        // deleting the rows it pages through skips every other page.
        $service->dueUnverifiedDeletion()->orderBy('id')->get()->each(function (User $user) use ($service, &$deleted) {
            // Resolved before the row goes, because after it has gone there is
            // nothing left to print.
            $label = sprintf('#%d %s', $user->id, $user->email);

            if ($this->dryRun) {
                $deleted++;
                $this->line(sprintf('  <fg=red>delete</> · %s', $label));

                return;
            }

            try {
                $service->deleteUnverified($user);

                $deleted++;
                $this->line(sprintf('  <fg=red>deleted</> · %s', $label));
            } catch (\Throwable $exception) {
                Log::channel('ezeh')->error('Unverified account deletion failed', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->line(sprintf('  <fg=red>failed</> · %s · %s', $label, $exception->getMessage()));
            }
        });

        return $deleted;
    }
}
