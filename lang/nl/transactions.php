<?php

return [
    'nav' => 'Transacties',
    'model' => 'transactie',
    'model_plural' => 'transacties',

    'sections' => [
        'trade' => 'Transactie',
        'amounts' => 'Bedragen',
        'amounts_hint' => 'Deze bedragen bepalen de cijfers op Portefeuille, Dividenden en Inzichten. Pas ze alleen aan als de CSV verkeerd is ingelezen.',
    ],

    'fields' => [
        'account' => 'Rekening',
        'instrument' => 'Instrument',
        'instrument_name' => 'Naam',
        'instrument_isin' => 'ISIN',
        'executed_at' => 'Uitgevoerd op',
        'type' => 'Type',
        'quantity' => 'Aantal',
        'price' => 'Koers',
        'price_currency' => 'Valuta koers',
        'trade_currency' => 'Valuta transactie',
        'fx_rate_to_eur' => 'Wisselkoers naar EUR',
        'fx_rate_to_eur_hint' => '1 eenheid van de transactievaluta in EUR. Laat leeg bij transacties in EUR.',
        'fee' => 'Kosten',
        'local_value' => 'Lokale waarde',
        'value_eur' => 'Waarde (EUR)',
        'total_eur' => 'Totaal (EUR)',
        'total_eur_hint' => 'Inclusief alle kosten — dit is de daadwerkelijk ingelegde EUR.',
        'source' => 'Bron',
    ],

    'types' => [
        'buy' => 'Koop',
        'sell' => 'Verkoop',
    ],

    'sources' => [
        'import' => 'Geïmporteerd',
        'manual' => 'Handmatig',
    ],

    'filters' => [
        'from' => 'Vanaf',
        'until' => 'Tot en met',
    ],

    'validation' => [
        'duplicate' => 'Deze transactie staat al op deze rekening — pas de bestaande transactie aan.',
        'duplicate_deleted' => 'Deze transactie is eerder verwijderd. Zet hem terug via het filter "verwijderd" in plaats van hem opnieuw in te voeren.',
    ],
];
