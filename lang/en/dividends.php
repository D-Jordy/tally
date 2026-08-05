<?php

return [
    'nav' => 'Dividends',
    'title' => 'Incoming dividends',

    'kpi' => [
        'next_12m' => 'Expected next 12 mo',
        'trailing_12m' => 'Received last 12 mo',
        'yield_on_cost' => 'Yield on cost',
        'paying_positions' => 'Dividend-paying positions',
    ],

    'sections' => [
        'calendar' => 'Payment calendar',
        'positions' => 'Dividend-paying positions',
    ],

    'calendar' => [
        'note' => 'Grouped by pay date, ex-date where none is known',
    ],

    'empty' => [
        'calendar' => 'No dividends expected in the next 12 months.',
    ],

    'table' => [
        'instrument' => 'Instrument',
        'ex_date' => 'Ex-date',
        'pay_date' => 'Pay date',
        'per_share' => '/share',
        'expected' => 'Expected',
        'value' => 'Value',
        'yield' => 'Yield',
        'yoc' => 'YOC',
        'certainty' => 'Certainty',
        'forward_12m' => 'Next 12 mo',
    ],

    'chart' => [
        'heading' => 'Expected dividend per month',
        'confirmed' => 'Confirmed',
        'expected' => 'Expected',
    ],

    'badge' => [
        'confirmed' => 'CONFIRMED',
        'estimate' => 'ESTIMATE',
    ],
];
