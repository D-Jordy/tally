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
        'upcoming' => 'Upcoming',
        'projected' => 'Projected',
        'positions' => 'Dividend-paying positions',
    ],

    'empty' => [
        'confirmed' => 'No confirmed dividends.',
        'projected' => 'No projections available.',
    ],

    'table' => [
        'instrument' => 'Instrument',
        'ex_date' => 'Ex-date',
        'per_share' => '/share',
        'expected' => 'Expected',
        'value' => 'Value',
        'yield' => 'Yield',
        'yoc' => 'YOC',
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
