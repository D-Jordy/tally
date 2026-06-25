<?php

return [
    'nav' => 'Accounts',
    'model' => 'account',
    'model_plural' => 'accounts',

    'fields' => [
        'name' => 'Name',
        'broker' => 'Broker',
        'last_import' => 'Last import',
    ],

    'import' => [
        'label' => 'Import',
        'heading' => 'Import CSV',
        'description' => 'Upload your DEGIRO Transactions CSV and/or Account statement CSV.',
        'submit' => 'Import',

        'transactions' => [
            'label' => 'Transactions CSV',
            'helper' => 'DEGIRO → Transactions → export (the file with your buy/sell orders).',
        ],
        'account' => [
            'label' => 'Account statement CSV',
            'helper' => 'DEGIRO → Account statement → export (the cash ledger with deposits, dividends and fees).',
        ],

        'group_transactions' => 'Transactions',
        'group_account' => 'Account statement',

        'no_file' => 'No file uploaded',
        'done' => 'Import complete',
        'done_errors' => 'Import completed with errors',
        'result_line' => ':label: :inserted added, :skipped skipped',
    ],
];
