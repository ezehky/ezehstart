<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Charges are kept as their own rows rather than folded into the amount, so a
 * receipt can show what was taken and why without anyone reverse-engineering it.
 */
enum TransactionChargeEnum: string
{
    use WithEnumHelpers;

    case FEE = 'fee';
    case TAX = 'tax';

    public function isFee(): bool
    {
        return $this === self::FEE;
    }

    public function isTax(): bool
    {
        return $this === self::TAX;
    }
}
