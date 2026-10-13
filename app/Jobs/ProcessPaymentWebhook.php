<?php

namespace App\Jobs;

use App\Models\PaymentWebhook;
use App\Services\Payment\PaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Work one stored webhook off the request cycle.
 *
 * The controller has already checked the signature and written the row, so the
 * vendor got its 200 straight away. This asks the vendor about the payment and
 * settles it. A vendor that could not be reached throws, and the queue tries
 * again with a widening gap; when it gives up, the row is marked failed and can
 * be retried from the admin screen.
 *
 * Unique per webhook, so a manual retry pressed while an automatic one is still
 * queued does not run it twice.
 */
class ProcessPaymentWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public PaymentWebhook $webhook)
    {
        $this->onConnection(config('payments.queue.connection'));
        $this->onQueue(config('payments.queue.name', 'default'));
        $this->afterCommit();
    }

    public function tries(): int
    {
        return max(1, (int) config('payments.queue.tries', 5));
    }

    /**
     * Seconds between attempts: half a minute, then two, ten, thirty, an hour.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->webhook->id;
    }

    public function handle(): void
    {
        app(PaymentService::class)->processWebhook($this->webhook);
    }

    public function failed(?Throwable $exception): void
    {
        app(PaymentService::class)->failWebhook($this->webhook, $exception?->getMessage() ?? 'The webhook could not be processed.');
    }
}
