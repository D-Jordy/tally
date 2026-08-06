<?php

return [
    'nav' => 'Instruments',
    'model' => 'instrument',
    'model_plural' => 'instruments',

    'sections' => [
        'identity' => 'Identity',
        'market_data' => 'Market data',
        'market_data_hint' => 'Correct these when Yahoo resolved the ISIN to the wrong listing. Changing the Yahoo symbol clears the stored prices and dividends, so the next refresh refetches them for the corrected symbol.',
        'position' => 'Your position',
        'position_hint' => 'Empty when you no longer hold this instrument.',
        'analysts' => 'Analysts',
    ],

    'fields' => [
        'name' => 'Name',
        'isin' => 'ISIN',
        'isin_hint' => 'Set by the importer — this is what the CSV is matched on.',
        'symbol' => 'Symbol',
        'yahoo_symbol' => 'Yahoo symbol',
        'yahoo_symbol_hint' => 'The ticker prices and dividends are fetched under, e.g. ASML.AS.',
        'sector' => 'Sector',
        'sector_hint' => 'Yahoo returns none for funds and ETFs — fill it in to keep the position out of "Other".',
        'exchange' => 'Exchange',
        'quote_currency' => 'Quote currency',
        'country' => 'Country',
        'dividend_yield' => 'Dividend yield',
        'analyst_target_price' => 'Target price',
        'analyst_rating' => 'Rating',
    ],

    'position' => [
        'quantity' => 'Quantity',
        'avg_cost' => 'Average cost',
        'current_value' => 'Current value',
        'unrealized' => 'Unrealised P/L',
        'dividends' => 'Dividends received',
        'yield' => 'Yield',
        'yield_on_cost' => 'Yield on cost',
    ],

    'relations' => [
        'transactions' => 'Your transactions',
        'dividends' => 'Dividend history',
    ],

    'dividends' => [
        'ex_date' => 'Ex-date',
        'pay_date' => 'Pay date',
        'amount_per_share' => 'Per share',
        'status' => 'Status',
        'statuses' => [
            'paid' => 'Paid',
            'confirmed' => 'Confirmed',
            'projected' => 'Estimate',
        ],
    ],

    'filters' => [
        'missing_symbol' => 'Missing symbol',
        'missing_sector' => 'Missing sector',
        'currency_mismatch' => 'Currency mismatch',
    ],

    'chart' => [
        'heading' => 'Price history',
        'close' => 'Close',
    ],

    'resync' => [
        'failed' => 'The symbol was saved, but refetching prices and dividends failed. They will be picked up by the next refresh.',
    ],
];
