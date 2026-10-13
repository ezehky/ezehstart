<?php

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The subscription will not renew. The time already paid for still runs to
 * current_period_ends_at, when SubscriptionEnded follows.
 */
class SubscriptionCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
