<?php

return [
    'nav' => 'Transactions',
    'model' => 'transaction',
    'model_plural' => 'transactions',

    'sections' => [
        'trade' => 'Trade',
        'amounts' => 'Amounts',
        'amounts_hint' => 'These figures drive the portfolio, dividend and insight numbers. Leave them as imported unless the CSV was parsed wrong.',
    ],

    'fields' => [
        'account' => 'Account',
        'instrument' => 'Instrument',
        'instrument_name' => 'Name',
        'instrument_isin' => 'ISIN',
        'executed_at' => 'Executed at',
        'type' => 'Type',
        'quantity' => 'Quantity',
        'price' => 'Price',
        'price_currency' => 'Price currency',
        'trade_currency' => 'Trade currency',
        'fx_rate_to_eur' => 'FX rate to EUR',
        'fx_rate_to_eur_hint' => '1 unit of the trade currency in EUR. Leave empty for EUR trades.',
        'fee' => 'Fee',
        'local_value' => 'Local value',
        'value_eur' => 'Value (EUR)',
        'total_eur' => 'Total (EUR)',
        'total_eur_hint' => 'Including all fees — this is the EUR actually deployed.',
        'source' => 'Source',
    ],

    'types' => [
        'buy' => 'Buy',
        'sell' => 'Sell',
    ],

    'sources' => [
        'import' => 'Imported',
        'manual' => 'Manual',
    ],

    'filters' => [
        'from' => 'From',
        'until' => 'Until',
    ],

    'validation' => [
        'duplicate' => 'This trade is already on file for this account — edit the existing transaction instead.',
        'duplicate_deleted' => 'This trade was deleted earlier. Restore it from the trashed filter instead of entering it again.',
    ],
];
