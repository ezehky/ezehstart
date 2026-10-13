<?php

namespace App\Console\Commands;

use App\Services\Payment\SubscriptionService;
use Illuminate\Console\Command;

class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'payments:sync-subscriptions';

    protected $description = 'Read every live subscription back from its vendor, and end the ones whose paid time has run out';

    public function handle(): int
    {
        $counts = app(SubscriptionService::class)->sync();

        $this->info(sprintf(
            'Checked %d · ended %d · unreachable %d',
            $counts['checked'],
            $counts['ended'],
            $counts['errors'],
        ));

        return self::SUCCESS;
    }
}
