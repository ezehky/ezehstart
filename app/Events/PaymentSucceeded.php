<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A gateway payment was verified with the vendor and the ledger row is now
 * CONFIRMED. Fired once per transaction, after the commit — a listener never
 * sees a confirmation that is about to roll back.
 *
 * This is the hook a project fulfils on: mark the order paid, open the course,
 * send the receipt. The transaction's transactionable is whatever it paid for.
 */
class PaymentSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Transaction $transaction) {}
}
