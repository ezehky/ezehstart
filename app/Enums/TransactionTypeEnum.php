<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum TransactionTypeEnum: string
{
    use WithEnumHelpers;

    case CREDIT = 'credit';
    case DEBIT = 'debit';

    /**
     * Paid straight through without ever touching a wallet — a card charge for a
     * single item. It leaves a ledger row but no balance movement, which is why
     * these rows carry TransactionWalletEnum::NONE.
     */
    case DIRECT = 'direct';

    public function isCredit(): bool
    {
        return $this === self::CREDIT;
    }

    public function isDebit(): bool
    {
        return $this === self::DEBIT;
    }

    public function isDirect(): bool
    {
        return $this === self::DIRECT;
    }
}
