<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;

class PruneActivityLogsCommand extends Command
{
    protected $signature = 'activity:prune-logs
                            {--days= : Override the configured retention window, in days}
                            {--chunk=1000 : How many rows to delete per pass}
                            {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete activity log entries older than the configured retention window';

    protected bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $service = app(ActivityLogService::class);

        // An explicit --days wins, so an administrator can prune once without first
        // changing a setting that then keeps pruning every night.
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : $service->retentionDays();

        if ($days <= 0) {
            $this->info('Activity log retention is off — nothing was pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        if ($this->dryRun) {
            $this->line(sprintf(
                '  <fg=yellow>would delete</> · %d entr(ies) older than %s',
                ActivityLog::query()->where('created_at', '<', $cutoff)->count(),
                $cutoff->toDayDateTimeString(),
            ));

            return self::SUCCESS;
        }

        $deleted = $service->prune($days, max(100, (int) $this->option('chunk')));

        $this->info(sprintf(
            'Pruned %d activity log entr(ies) older than %s (%d day window).',
            $deleted,
            $cutoff->toDayDateTimeString(),
            $days,
        ));

        return self::SUCCESS;
    }
}
