<?php

return [
    'nav' => 'Dividenden',
    'title' => 'Inkomende dividenden',

    'kpi' => [
        'next_12m' => 'Verwacht komende 12 mnd',
        'trailing_12m' => 'Ontvangen laatste 12 mnd',
        'yield_on_cost' => 'Yield on cost',
        'paying_positions' => 'Dividend-betalende posities',
    ],

    'sections' => [
        'calendar' => 'Betaalkalender',
        'positions' => 'Dividend-betalende posities',
    ],

    'calendar' => [
        'note' => 'Betaaldatum waar bekend, anders ex-datum',
        'ex' => 'ex',
        'ex_hint' => 'Geen betaaldatum bekend — dit is de ex-datum',
    ],

    'empty' => [
        'calendar' => 'Geen dividend verwacht in de komende 12 maanden.',
    ],

    'table' => [
        'instrument' => 'Instrument',
        'date' => 'Datum',
        'per_share' => '/aandeel',
        'expected' => 'Verwacht',
        'value' => 'Waarde',
        'yield' => 'Yield',
        'yoc' => 'YOC',
        'forward_12m' => 'Komende 12 mnd',
    ],

    'chart' => [
        'heading' => 'Verwacht dividend per maand',
        'confirmed' => 'Bevestigd',
        'expected' => 'Verwacht',
    ],

    'badge' => [
        'confirmed' => 'BEVESTIGD',
        'estimate' => 'SCHATTING',
    ],
];
