<?php

namespace App\Events;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A renewal was paid, checked with the vendor, and written to the ledger. The
 * period has moved on; $transaction is the renewal's row.
 */
class SubscriptionRenewed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Transaction $transaction,
    ) {}
}
