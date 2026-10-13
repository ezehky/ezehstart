<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The vendor reported a gateway payment as failed or cancelled, and the ledger
 * row has been settled that way. Final: a settled row is never re-settled.
 */
class PaymentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public ?string $reason = null,
    ) {}
}
