<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What a webhook is about, once each vendor's own event name has been read.
 *
 * Paystack says charge.success, Stripe says checkout.session.completed, Monnify
 * says SUCCESSFUL_TRANSACTION. Each gateway service maps its names onto these,
 * so PaymentService decides what to do from one vocabulary rather than seven.
 */
enum PaymentWebhookTopicEnum: string
{
    use WithEnumHelpers;

    case CHARGE = 'charge';
    case REFUND = 'refund';
    case SUBSCRIPTION = 'subscription';

    /** A signed event this application has no handler for. Stored, never acted on. */
    case OTHER = 'other';

    public function isCharge(): bool
    {
        return $this === self::CHARGE;
    }

    public function isRefund(): bool
    {
        return $this === self::REFUND;
    }

    public function isSubscription(): bool
    {
        return $this === self::SUBSCRIPTION;
    }

    public function isOther(): bool
    {
        return $this === self::OTHER;
    }

    public function color(): string
    {
        return match ($this) {
            self::CHARGE => 'green',
            self::REFUND => 'yellow',
            self::SUBSCRIPTION => 'blue',
            self::OTHER => 'zinc',
        };
    }
}
