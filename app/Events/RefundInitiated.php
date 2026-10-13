<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A vendor accepted a refund and is working on it. The refund row is
 * PROCESSING; RefundProcessed or RefundFailed follows once the vendor says.
 * $refund->transactionable is the payment being refunded.
 */
class RefundInitiated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Transaction $refund) {}
}
