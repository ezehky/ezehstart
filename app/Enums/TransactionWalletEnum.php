<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Which balance the row moves.
 *
 * NONE is not "no wallet chosen" — it is the deliberate answer for a direct
 * payment that never touches one, and it is what keeps balance recalculations
 * from picking those rows up.
 */
enum TransactionWalletEnum: string
{
    use WithEnumHelpers;

    case BALANCE = 'balance';
    case NONE = 'none';

    public function isBalance(): bool
    {
        return $this === self::BALANCE;
    }

    public function isNone(): bool
    {
        return $this === self::NONE;
    }
}
