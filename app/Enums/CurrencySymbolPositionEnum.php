<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Which side of the amount a currency's symbol is written on — $10.00 against
 * 10.00 kr. A property of the currency, not of the reader: a Swede reading
 * dollars still sees $10.00.
 */
enum CurrencySymbolPositionEnum: string
{
    use WithEnumHelpers;

    case BEFORE = 'before';
    case AFTER = 'after';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::BEFORE => 'Before the amount ($10.00)',
            self::AFTER => 'After the amount (10.00 kr)',
        };

        return $lowercase ? mb_strtolower($label) : $label;
    }

    /**
     * The symbol and an already-formatted amount, joined on the right side. A
     * symbol written after is spaced off, because one written flush reads as part
     * of the number — "10.00kr".
     */
    public function place(string $symbol, string $money): string
    {
        return match ($this) {
            self::BEFORE => "{$symbol}{$money}",
            self::AFTER => "{$money} {$symbol}",
        };
    }
}
