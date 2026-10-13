<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money went back: the vendor completed the refund, or an administrator
 * recorded one paid back by hand. The refund row is CONFIRMED, and for a
 * deposit the balance has been debited.
 *
 * The hook to undo fulfilment on — cancel the order, close the enrolment.
 * $refund->transactionable is the payment that was refunded.
 */
class RefundProcessed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Transaction $refund) {}
}
