<?php

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A renewal failed and the vendor is retrying. The subscription is PAST_DUE;
 * whether access continues meanwhile is the project's call.
 */
class SubscriptionPaymentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
