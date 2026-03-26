<?php

return [
    'continuation_access' => [
        'expires_in_days' => (int) env('PROPOSAL_CONTINUATION_EXPIRES_DAYS', 7),
        'code_length' => (int) env('PROPOSAL_CONTINUATION_CODE_LENGTH', 6),
    ],

    'mail' => [
        'mailer' => env('PROPOSAL_MAILER', env('MAIL_MAILER', 'log')),
        'from' => [
            'address' => env('PROPOSAL_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('PROPOSAL_MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'BSI Capital')),
        ],
        'outlook' => [
            'auth_mode' => env('OUTLOOK_MAIL_AUTH_MODE', 'smtp_oauth'),
            'tenant_id' => env('OUTLOOK_TENANT_ID'),
            'client_id' => env('OUTLOOK_CLIENT_ID'),
            'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
            'mailbox' => env('OUTLOOK_MAILBOX'),
        ],
    ],
];
