<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A checkout was opened with a vendor and the customer is about to be sent to
 * it. The transaction is PROCESSING and its gateway row holds the link.
 */
class PaymentInitialized implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Transaction $transaction) {}
}
