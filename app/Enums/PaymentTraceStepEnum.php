<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Which part of a payment a trace row records.
 */
enum PaymentTraceStepEnum: string
{
    use WithEnumHelpers;

    /** Asking the vendor for an access token (PayPal, Monnify). */
    case AUTHENTICATE = 'authenticate';

    case INITIALIZE = 'initialize';
    case VERIFY = 'verify';
    case WEBHOOK = 'webhook';
    case REFUND = 'refund';
    case SUBSCRIPTION = 'subscription';
    case HEALTH = 'health';

    public function color(): string
    {
        return match ($this) {
            self::AUTHENTICATE, self::HEALTH => 'zinc',
            self::INITIALIZE => 'blue',
            self::VERIFY => 'green',
            self::WEBHOOK => 'purple',
            self::REFUND => 'yellow',
            self::SUBSCRIPTION => 'sky',
        };
    }
}
