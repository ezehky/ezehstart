<?php

namespace App\Console\Commands;

use App\Models\PaymentWebhook;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

class RetryPaymentWebhooksCommand extends Command
{
    protected $signature = 'payments:retry-webhooks
                            {--id=* : Only these webhook ids}
                            {--dry-run : List what would be retried without queueing anything}';

    protected $description = 'Put failed payment webhooks back on the queue';

    public function handle(): int
    {
        $service = app(PaymentService::class);

        $webhooks = PaymentWebhook::query()
            ->failed()
            ->when($this->option('id'), fn ($query, $ids) => $query->whereKey($ids))
            ->orderBy('id')
            ->get();

        foreach ($webhooks as $webhook) {
            if ($this->option('dry-run')) {
                $this->line("  <fg=yellow>would retry</> · #{$webhook->id} {$webhook->label()}");

                continue;
            }

            $service->retryWebhook($webhook);
            $this->line("  <fg=green>queued</> · #{$webhook->id} {$webhook->label()}");
        }

        $this->info(sprintf('%d failed webhook(s) %s.', $webhooks->count(), $this->option('dry-run') ? 'found' : 'queued'));

        return self::SUCCESS;
    }
}
