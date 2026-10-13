<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile
                            {--after= : Only checkouts older than this many minutes (default from config)}
                            {--abandon-after= : Close unpaid checkouts this many hours past expiry (default from config)}
                            {--dry-run : Count what would be checked without asking any vendor}';

    protected $description = 'Ask the vendor about every gateway payment still processing, and settle what has finished';

    public function handle(): int
    {
        $counts = app(PaymentService::class)->reconcile(
            $this->option('after') !== null ? (int) $this->option('after') : null,
            $this->option('abandon-after') !== null ? (int) $this->option('abandon-after') : null,
            (bool) $this->option('dry-run'),
        );

        if ($this->option('dry-run')) {
            $this->line(sprintf('  <fg=yellow>would check</> · %d processing checkout(s)', $counts['checked']));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Checked %d · confirmed %d · closed %d · abandoned %d · unreachable %d',
            $counts['checked'],
            $counts['confirmed'],
            $counts['closed'],
            $counts['abandoned'],
            $counts['errors'],
        ));

        return self::SUCCESS;
    }
}
