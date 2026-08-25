<?php

return [
    'access_tokens' => [
        'expires_in_days' => (int) env('NIMBUS_ACCESS_TOKEN_EXPIRES_DAYS', 7),
    ],

    'mail' => [
        'mailer' => env('NIMBUS_MAILER', env('MAIL_MAILER', 'log')),
        'from' => [
            'address' => env('NIMBUS_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('NIMBUS_MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'BSI Capital')),
        ],
    ],

    // Dedicated stable secret for PII blind indexes — independent from APP_KEY rotation.
    // Must be set via NIMBUS_PII_BLIND_INDEX_KEY. Changing APP_KEY must NOT change blind indexes.
    'pii_blind_index_key' => env('NIMBUS_PII_BLIND_INDEX_KEY'),
    'pii_blind_index_version' => env('NIMBUS_PII_BLIND_INDEX_VERSION', 'v1'),

    'migration' => [
        'legacy_storage_root' => env('NIMBUS_LEGACY_STORAGE_ROOT', storage_path('app/private/nimbus-legacy')),
        'control_connection' => env('NIMBUS_MIGRATION_CONTROL_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    ],
];
