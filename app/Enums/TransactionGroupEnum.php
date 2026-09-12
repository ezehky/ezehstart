<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What the money was for.
 *
 * This is the column the admin filters and reports on, so a project adding a
 * domain adds its cases here rather than inventing a second classification
 * alongside it.
 */
enum TransactionGroupEnum: string
{
    use WithEnumHelpers;

    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case PURCHASE = 'purchase';
    case REFUND = 'refund';

    /** A manual correction by an administrator, always evidenced in the log. */
    case ADJUSTMENT = 'adjustment';

    public function isDeposit(): bool
    {
        return $this === self::DEPOSIT;
    }

    public function isWithdrawal(): bool
    {
        return $this === self::WITHDRAWAL;
    }

    public function isPurchase(): bool
    {
        return $this === self::PURCHASE;
    }

    public function isRefund(): bool
    {
        return $this === self::REFUND;
    }

    public function isAdjustment(): bool
    {
        return $this === self::ADJUSTMENT;
    }

    /**
     * Colors
     */
    public function color(): string
    {
        return match ($this) {
            self::DEPOSIT => 'green',
            self::WITHDRAWAL => 'red',
            self::PURCHASE => 'blue',
            self::REFUND => 'yellow',
            self::ADJUSTMENT => 'gray',
        };
    }
}
