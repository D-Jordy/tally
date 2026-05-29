<?php

namespace App\Services\Import;

/**
 * Rule #1: GBp (yfinance) and GBX (DEGIRO) both mean pence.
 * Divide by 100, store as GBP.
 * This is the single place where that conversion happens.
 */
class CurrencyNormaliser
{
    private const PENCE_CURRENCIES = ['GBp', 'GBX', 'GBx'];

    public static function normaliseCurrency(string $currency): string
    {
        return in_array($currency, self::PENCE_CURRENCIES, true) ? 'GBP' : $currency;
    }

    /**
     * Normalise a money amount + its currency.
     * Returns [normalised_amount, normalised_currency].
     */
    public static function normalise(string|float $amount, string $currency): array
    {
        if (in_array($currency, self::PENCE_CURRENCIES, true)) {
            return [bcdiv((string) $amount, '100', 8), 'GBP'];
        }

        return [(string) $amount, $currency];
    }

    public static function isPence(string $currency): bool
    {
        return in_array($currency, self::PENCE_CURRENCIES, true);
    }
}
