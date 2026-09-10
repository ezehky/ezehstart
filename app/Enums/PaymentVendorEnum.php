<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The processor behind a transaction_gateways row.
 *
 * MANUAL is the case that always applies: a bank transfer reviewed by an
 * administrator is still a gateway row, and giving it a vendor saves every
 * reconciliation query from special-casing a null.
 */
enum PaymentVendorEnum: string
{
    use WithEnumHelpers;

    case MANUAL = 'manual';
    case PAYSTACK = 'paystack';
    case FLUTTERWAVE = 'flutterwave';

    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }

    public function isPaystack(): bool
    {
        return $this === self::PAYSTACK;
    }

    public function isFlutterwave(): bool
    {
        return $this === self::FLUTTERWAVE;
    }

    /**
     * Keys live in the environment rather than the site configuration, so a vendor
     * is only offered once someone has actually filled them in.
     */
    public function isConfigured(): bool
    {
        return $this->isManual() || (bool) config("services.{$this->value}.secret");
    }
}
