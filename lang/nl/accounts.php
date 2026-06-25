<?php

return [
    'nav' => 'Rekeningen',
    'model' => 'rekening',
    'model_plural' => 'rekeningen',

    'fields' => [
        'name' => 'Naam',
        'broker' => 'Broker',
        'last_import' => 'Laatste import',
    ],

    'import' => [
        'label' => 'Importeren',
        'heading' => 'CSV importeren',
        'description' => 'Upload je DEGIRO Transacties-CSV en/of Rekeningoverzicht-CSV.',
        'submit' => 'Importeren',

        'transactions' => [
            'label' => 'Transacties-CSV',
            'helper' => 'DEGIRO → Transacties → exporteren (het bestand met je koop/verkoop-orders).',
        ],
        'account' => [
            'label' => 'Rekeningoverzicht-CSV',
            'helper' => 'DEGIRO → Rekeningoverzicht → exporteren (het kasoverzicht met stortingen, dividend en kosten).',
        ],

        'group_transactions' => 'Transacties',
        'group_account' => 'Rekeningoverzicht',

        'no_file' => 'Geen bestand geüpload',
        'done' => 'Import voltooid',
        'done_errors' => 'Import voltooid met fouten',
        'result_line' => ':label: :inserted toegevoegd, :skipped overgeslagen',
    ],
];
