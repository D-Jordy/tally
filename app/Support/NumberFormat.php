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
 */
final class NumberFormat
{
    public const LOCALE = 'nl_NL';

    /** Decimals shown for money, quantities and prices alike. */
    public const DECIMALS = 2;

    public static function js(): string
    {
        return str_replace('_', '-', self::LOCALE);
    }
}
