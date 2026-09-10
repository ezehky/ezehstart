<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * How the money reached us.
 *
 * Distinct from TransactionGroupEnum, which says what it was for: a purchase can
 * arrive by gateway, by bank transfer, or be paid out of an existing balance, and
 * support has to be able to tell those three apart.
 */
enum TransactionViaEnum: string
{
    use WithEnumHelpers;

    case PAYMENT_GATEWAY = 'payment-gateway';
    case BANK_TRANSFER = 'bank-transfer';
    case BALANCE = 'balance';

    /** Created by the system or an administrator, with no external movement. */
    case PLATFORM = 'platform';

    public function isPaymentGateway(): bool
    {
        return $this === self::PAYMENT_GATEWAY;
    }

    public function isBankTransfer(): bool
    {
        return $this === self::BANK_TRANSFER;
    }

    public function isBalance(): bool
    {
        return $this === self::BALANCE;
    }

    public function isPlatform(): bool
    {
        return $this === self::PLATFORM;
    }

    /**
     * A bank transfer is the only route that arrives with a document attached, so
     * this is what the evidence upload and the review queue both key off.
     */
    public function needsEvidence(): bool
    {
        return $this === self::BANK_TRANSFER;
    }
}
