<?php

namespace App\Console\Commands;

use App\Enums\ActivityActionEnum;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ProcessAccountDeletionsCommand extends Command
{
    protected $signature = 'account:process-deletions
                            {--user=* : Process these user ids, ignoring the scheduled date}
                            {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete or anonymize accounts whose deletion grace period has run out';

    protected bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $service = app(AccountDeletionService::class);
        $deleted = 0;
        $anonymized = 0;

        $this->dueAccounts()->each(function (User $user) use ($service, &$deleted, &$anonymized) {
            // Resolved before the row goes, because after it has gone there is
            // nothing left to print.
            $label = sprintf('#%d %s <%s>', $user->id, $user->name, $user->email);

            if ($this->dryRun) {
                $outcome = ! $service->hasSignificantActivity($user) || ! $service->anonymizesAfterDeletion()
                    ? ActivityActionEnum::ACCOUNT_DELETE
                    : ActivityActionEnum::ACCOUNT_ANONYMIZE;

                $this->report($label, $outcome);
                $outcome === ActivityActionEnum::ACCOUNT_DELETE ? $deleted++ : $anonymized++;

                return;
            }

            try {
                $outcome = $service->finalize($user);
            } catch (\Throwable $exception) {
                // One account with a relation nobody thought about must not stop
                // the rest of the queue from being honoured.
                Log::channel('ezeh')->error('Account deletion failed', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->line(sprintf('  <fg=red>failed</> · %s · %s', $label, $exception->getMessage()));

                return;
            }

            $this->report($label, $outcome);
            $outcome === ActivityActionEnum::ACCOUNT_DELETE ? $deleted++ : $anonymized++;
        });

        $this->info(sprintf(
            '%s %d account(s) permanently and anonymized %d.',
            $this->dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $anonymized,
        ));

        return self::SUCCESS;
    }

    /**
     * Accounts whose grace period has expired.
     *
     * The --user list bypasses the date but not the status: an account that was
     * never scheduled, or one already put back, is not something this command
     * deletes because somebody typed its id.
     *
     * @return Collection<int, User>
     */
    protected function dueAccounts(): Collection
    {
        $ids = (array) $this->option('user');

        return User::query()
            ->when(
                $ids,
                fn ($query) => $query->pendingDeletion()->whereIn('id', $ids),
                fn ($query) => $query->dueForDeletion(),
            )
            ->orderBy('deletion_scheduled_at')
            ->get();
    }

    /**
     * One line per account, coloured by which of the two things happened to it.
     */
    protected function report(string $label, ActivityActionEnum $outcome): void
    {
        $isDelete = $outcome === ActivityActionEnum::ACCOUNT_DELETE;

        $this->line(sprintf(
            '  <fg=%s>%s</> · %s',
            $isDelete ? 'red' : 'yellow',
            $isDelete ? 'deleted' : 'anonymized',
            $label,
        ));
    }
}
