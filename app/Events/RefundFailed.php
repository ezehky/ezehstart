<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The vendor refused or abandoned a refund. The refund row is FAILED and no
 * longer counts against what can be refunded, so it can be tried again.
 */
class RefundFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Transaction $refund,
        public ?string $reason = null,
    ) {}
}
