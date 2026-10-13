<?php

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The subscription is over — cancelled and out of paid time, or ended by the
 * vendor. The hook to take away what the plan gave.
 */
class SubscriptionEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
