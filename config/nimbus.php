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
];
