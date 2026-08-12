<?php

return [
    'nav' => 'Portfolio',
    'title' => 'Portfolio',

    'refresh' => [
        'label' => 'Verversen',
        'done' => 'Marktdata bijgewerkt',
    ],

    'chart' => [
        'heading' => 'Portefeuillewaarde',
        'value' => 'Waarde',
        'invested' => 'Ingelegd',
        'dividends' => 'Dividenden',
        'pl' => 'W/V',
        'mode' => [
            'value' => 'Waarde',
            'pl' => 'W/V',
            'roi' => 'Rendement',
        ],
        'range' => [
            '1M' => '1M',
            '6M' => '6M',
            '1Y' => '1J',
            'ALL' => 'ALLES',
        ],
    ],

    'kpi' => [
        'market_value' => 'Marktwaarde',
        'deposited' => 'Ingelegd',
        'net_gain' => 'Nettowinst',
        'unrealized' => 'Ongerealiseerd',
        'realized' => 'Gerealiseerd',
        'dividends' => 'Dividenden',
        'fees' => 'Kosten',
    ],

    'sections' => [
        'positions' => 'Posities',
    ],

    'table' => [
        'instrument' => 'Instrument',
        'quantity' => 'Aantal',
        'avg_cost' => 'Gem. kostprijs',
        'price' => 'Koers',
        'value' => 'Waarde',
        'unrealized' => 'Ongerealiseerd',
        'dividend' => 'Dividend',
    ],

    'closed' => [
        'title' => 'Gesloten posities',
        'opened' => 'Geopend',
        'closed' => 'Gesloten',
        'deployed' => 'Ingelegd',
        'total' => 'Totaal',
        'empty' => 'Nog niets volledig verkocht.',
    ],

    'empty' => [
        'title' => 'Nog geen posities',
        'subtitle' => 'Importeer je DEGIRO-transacties om te beginnen.',
        'import' => 'CSV importeren',
    ],
];
