<?php

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The first payment was confirmed and the subscription is ACTIVE. The hook to
 * grant what the plan gives.
 */
class SubscriptionStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
