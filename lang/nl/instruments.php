<?php

return [
    'nav' => 'Instrumenten',
    'model' => 'instrument',
    'model_plural' => 'instrumenten',

    'sections' => [
        'identity' => 'Identificatie',
        'market_data' => 'Marktdata',
        'market_data_hint' => 'Pas dit aan als Yahoo de ISIN aan de verkeerde notering heeft gekoppeld. Bij een gewijzigd Yahoo-symbool worden de opgeslagen koersen en dividenden gewist, zodat de volgende verversing ze opnieuw ophaalt voor het juiste symbool.',
        'position' => 'Jouw positie',
        'position_hint' => 'Leeg wanneer je dit instrument niet meer bezit.',
        'analysts' => 'Analisten',
    ],

    'fields' => [
        'name' => 'Naam',
        'isin' => 'ISIN',
        'isin_hint' => 'Gezet door de importer — hierop wordt de CSV gematcht.',
        'symbol' => 'Symbool',
        'yahoo_symbol' => 'Yahoo-symbool',
        'yahoo_symbol_hint' => 'De ticker waaronder koersen en dividenden worden opgehaald, bijv. ASML.AS.',
        'sector' => 'Sector',
        'sector_hint' => 'Yahoo geeft er geen voor fondsen en ETF\'s — vul hem in om de positie uit "Overig" te houden.',
        'exchange' => 'Beurs',
        'quote_currency' => 'Noteringsvaluta',
        'country' => 'Land',
        'dividend_yield' => 'Dividendrendement',
        'analyst_target_price' => 'Koersdoel',
        'analyst_rating' => 'Advies',
    ],

    'position' => [
        'quantity' => 'Aantal',
        'avg_cost' => 'Gemiddelde kostprijs',
        'current_value' => 'Huidige waarde',
        'unrealized' => 'Ongerealiseerd resultaat',
        'dividends' => 'Ontvangen dividend',
        'yield' => 'Rendement',
        'yield_on_cost' => 'Rendement op kostprijs',
    ],

    'relations' => [
        'transactions' => 'Jouw transacties',
        'dividends' => 'Dividendhistorie',
    ],

    'dividends' => [
        'ex_date' => 'Ex-datum',
        'pay_date' => 'Betaaldatum',
        'amount_per_share' => 'Per aandeel',
        'status' => 'Status',
        'statuses' => [
            'paid' => 'Uitbetaald',
            'confirmed' => 'Bevestigd',
            'projected' => 'Schatting',
        ],
    ],

    'filters' => [
        'missing_symbol' => 'Symbool ontbreekt',
        'missing_sector' => 'Sector ontbreekt',
        'currency_mismatch' => 'Valuta wijkt af',
    ],

    'chart' => [
        'heading' => 'Koershistorie',
        'close' => 'Slotkoers',
    ],

    'resync' => [
        'failed' => 'Het symbool is opgeslagen, maar het opnieuw ophalen van koersen en dividenden is mislukt. De volgende verversing pikt ze op.',
    ],
];
