<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money moved, but not in a way the ledger can accept on its own: the amount or
 * currency the vendor reports differs from what was asked for, or a payment
 * landed on a row that had already been closed. Nothing is credited. The row is
 * left where an administrator will find it, and the reason says why.
 */
class PaymentNeedsReview implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public string $reason,
    ) {}
}
