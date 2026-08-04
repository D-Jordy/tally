<?php

namespace App\Support;

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

    public static function js(): string
    {
        return str_replace('_', '-', self::LOCALE);
    }
}
