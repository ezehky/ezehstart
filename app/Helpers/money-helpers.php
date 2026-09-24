<?php

use App\Enums\CurrencySymbolPositionEnum;
use App\Models\User;
use App\Services\CurrencyService;

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
     * Format an amount with its currency symbol.
     *
     * The amount is taken to be in the site default currency — the one the ledger
     * is written in — and converted into the viewer's own currency on the way out.
     * Passing a symbol says the amount is already in that currency, so nothing is
     * converted and the symbol is printed as given.
     *
     * @param  float  $amount  The amount, in the default currency unless $default says otherwise.
     * @param  string|null  $default  A symbol the amount is already in. Skips conversion.
     * @param  bool  $decodeHtml  Return the glyph rather than its HTML entity, for anywhere HTML is not rendered.
     * @param  int  $decimals  Number of decimal places (default: 2).
     * @param  bool  $convert  False prints the default currency even for a viewer who picked another.
     * @param  User|null  $user  Whose currency to read in. Defaults to whoever is signed in.
     * @return string Formatted money string with currency symbol.
     */
    function kMoneyFormat(
        float $amount,
        ?string $default = null,
        bool $decodeHtml = false,
        int $decimals = 2,
        bool $convert = true,
        ?User $user = null,
    ): string {
        // A symbol passed in has no currency row to say which side it goes on, so
        // it goes in front, where every symbol went before currencies had a side.
        $position = CurrencySymbolPositionEnum::BEFORE;

        if ($default) {
            $symbol = $default;
        } else {
            $currency = $convert ? kActiveCurrency($user) : kDefaultCurrency();

            $amount = $convert ? kCurrencyConvert($amount, $currency) : $amount;
            $symbol = $currency['symbol'];
            $position = CurrencySymbolPositionEnum::tryFrom($currency['symbol_position'] ?? '') ?? $position;
        }

        // FORMAT MONEY
        $money = kMoney($amount, $decimals);

        $formatted = $position->place($symbol, $money);

        return $decodeHtml ? html_entity_decode($formatted) : $formatted;
    }
}

if (! function_exists('kDefaultCurrency')) {
    /**
     * The currency the ledger is written in, as the array CurrencyService caches.
     *
     * @return array{id: int|null, name: string, code: string, symbol: string, symbol_position: string, rate: float, is_default: bool}
     */
    function kDefaultCurrency(): array
    {
        try {
            return app(CurrencyService::class)->default();
        } catch (Throwable) {
            // Called before the container can resolve anything — a unit test, or
            // a helper reached during boot. What the site printed before
            // currencies were rows is the right answer there.
            return CurrencyService::FALLBACK;
        }
    }
}

if (! function_exists('kActiveCurrency')) {
    /**
     * The currency amounts are shown in for this account, or for whoever is
     * signed in when none is given. The site default for a guest, and for anybody
     * who never picked one.
     *
     * @return array{id: int|null, name: string, code: string, symbol: string, symbol_position: string, rate: float, is_default: bool}
     */
    function kActiveCurrency(?User $user = null): array
    {
        try {
            return app(CurrencyService::class)->forUser($user ?? auth()->user());
        } catch (Throwable) {
            return CurrencyService::FALLBACK;
        }
    }
}

if (! function_exists('kCurrencyConvert')) {
    /**
     * Convert an amount between two currencies. $from defaults to the site
     * default, which is what every stored amount is in.
     *
     * @param  array  $to  The currency array to convert into.
     * @param  array|null  $from  The currency array the amount is in.
     */
    function kCurrencyConvert(float $amount, array $to, ?array $from = null): float
    {
        try {
            return app(CurrencyService::class)->convert($amount, $to, $from);
        } catch (Throwable) {
            return $amount;
        }
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
