<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentTraceService;
use Illuminate\Console\Command;

class PrunePaymentTracesCommand extends Command
{
    protected $signature = 'payments:prune-traces
                            {--days= : Override the configured retention window, in days}';

    protected $description = 'Delete payment traces older than the retention window';

    public function handle(): int
    {
        $service = app(PaymentTraceService::class);

        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : $service->retentionDays();

        $deleted = $service->prune($days);

        $this->info(sprintf('Pruned %d payment trace(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
