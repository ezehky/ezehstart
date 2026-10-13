<?php

namespace App\Events;

use App\Models\PaymentWebhook;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A signed webhook was worked. Fired for every topic, including the ones the
 * kit itself does nothing with, so a project can act on vendor events the
 * ledger has no opinion about — a dispute opened, a payout settled.
 *
 * The payload is the vendor's own. For anything that moves money, re-verify
 * with the vendor rather than trusting the payload's status: it is a snapshot
 * from when the vendor sent it.
 */
class PaymentWebhookReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public PaymentWebhook $webhook) {}
}
