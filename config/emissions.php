<?php

return [
    'access' => [
        'expires_in_days' => (int) env('EMISSION_ACCESS_EXPIRES_DAYS', 7),
        'code_length' => (int) env('EMISSION_ACCESS_CODE_LENGTH', 6),
    ],
];
