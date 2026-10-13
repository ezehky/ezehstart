<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What a subscription webhook means, once a vendor's own event name has been
 * read. Only a renewal and a failed renewal need handling of their own; every
 * other change — created, cancelled, paused, updated — is CHANGED, and the
 * subscription is simply re-read from the vendor.
 */
enum SubscriptionEventEnum: string
{
    use WithEnumHelpers;

    case RENEWED = 'renewed';
    case PAYMENT_FAILED = 'payment-failed';
    case CHANGED = 'changed';

    public function isRenewed(): bool
    {
        return $this === self::RENEWED;
    }

    public function isPaymentFailed(): bool
    {
        return $this === self::PAYMENT_FAILED;
    }

    public function isChanged(): bool
    {
        return $this === self::CHANGED;
    }
}
