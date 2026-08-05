<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Everything in Tally is reported in EUR, so numbers and money use the euro
 * notation (1.234,56) whatever language the interface is in. Only the words
 * follow Accept-Language — see App\Http\Middleware\SetLocale.
 *
 * Registered globally through Number::useLocale() in AppServiceProvider, which
 * also covers Filament's ->money() and ->numeric() columns since those call
 * Illuminate\Support\Number underneath. The JS variant is for the chart
 * tooltips, which format client-side through Intl.NumberFormat.
 *
 * Two shapes of number:
 *   - Money keeps a fixed DECIMALS, because € 24.839,20 reads as an amount and
 *     € 24.839,2 does not. Number::currency() does this on its own.
 *   - Everything else — quantities, prices, per-share dividends — is shown as
 *     precisely as that instrument needs, up to MAX_DECIMALS, with trailing
 *     zeros dropped. Pass it as `maxPrecision`/`maxDecimalPlaces`, never as a
 *     fixed decimal count: 60 shares should read 60, and a 0,0525 dividend
 *     must not round away to 0,05.
 */
final class NumberFormat
{
    public const LOCALE = 'nl_NL';

    /** Fixed decimals for money and percentages. */
    public const DECIMALS = 2;

    /** Ceiling for trimmed numbers: quantities, prices, per-share amounts. */
    public const MAX_DECIMALS = 4;

    /**
     * Currency symbols for amounts that are not in EUR — prices, per-share
     * dividends. Tables show the sign, never the code. Anything exotic enough to
     * be missing here falls back to its code, which still reads unambiguously.
     */
    private const SYMBOLS = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'CHF' => 'CHF',
        'JPY' => '¥',
        'SEK' => 'kr',
        'DKK' => 'kr',
        'NOK' => 'kr',
        'PLN' => 'zł',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'HKD' => 'HK$',
    ];

    public static function js(): string
    {
        return str_replace('_', '-', self::LOCALE);
    }

    public static function symbol(?string $currency): string
    {
        return self::SYMBOLS[strtoupper((string) $currency)] ?? (string) $currency;
    }

    /**
     * An amount in its own currency: symbol, then the fixed two decimals money
     * always keeps. Prices and cost bases are money — only quantities and
     * per-share dividends are trimmed, where a 0,0525 must survive.
     */
    public static function money(float|int|string|null $value, ?string $currency): ?string
    {
        return $value === null
            ? null
            : self::symbol($currency).' '.Number::format((float) $value, precision: self::DECIMALS);
    }
}
