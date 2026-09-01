<?php

// ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// MONEY & NUMBER HELPERS
if (! function_exists('kMoney')) {
    /**
     * Format a float number as a money string with specified decimals.
     *
     * @param  float  $number  The number to format.
     * @param  int  $decimals  Number of decimal places (default: 2).
     * @return string Formatted money string.
     */
    function kMoney(float $number, int $decimals = 2): string
    {
        $number = number_format($number, $decimals);

        //
        return rtrim(rtrim($number, '0'), '.');
    }
}

if (! function_exists('kMoneyFormat')) {
    /**
     * Format a float number as a money string with currency symbol.
     *
     * @param  float  $amount  The amount to format.
     * @param  string|null  $default  Optional currency symbol (default: '₦').
     * @param  int  $decimals  Number of decimal places (default: 2).
     * @return string Formatted money string with currency symbol.
     */
    function kMoneyFormat(
        float $amount,
        ?string $default = null,
        bool $decodeHtml = false,
        int $decimals = 2
    ): string {

        $symbol = $default ?: '&#8358;';

        // FORMAT MONEY
        $money = kMoney($amount, $decimals);

        // HANDLE SYMBOL POSITION AFTER
        return $decodeHtml ? html_entity_decode("{$symbol}{$money}") : "{$symbol}{$money}";
    }
}

if (! function_exists('kPointFormat')) {
    /**
     * Format a float number as a point string with 'pv' suffix.
     *
     * @param  float|null  $point  The point value to format.
     * @return string Formatted point string or '-' if null.
     */
    function kPointFormat(?float $point): string
    {
        if ($point === null) {
            return '-';
        }

        return kPluralize('pv', $point);
    }
}
