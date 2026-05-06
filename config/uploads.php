<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-context upload limits
    |--------------------------------------------------------------------------
    |
    | All *_max_kb values are in kilobytes (for Filament / Laravel validation).
    | All *_max_bytes values are in bytes (for manual validation in Actions).
    |
    */

    'proposal_continuation' => [
        'max_bytes' => (int) env('UPLOAD_PROPOSAL_MAX_BYTES', 20 * 1024 * 1024),
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    'admin_response' => [
        'max_kb' => (int) env('UPLOAD_ADMIN_RESPONSE_MAX_KB', 51200),
    ],

    'document' => [
        'max_kb' => (int) env('UPLOAD_DOCUMENT_MAX_KB', 51200),
    ],

    'resume' => [
        'max_kb' => (int) env('UPLOAD_RESUME_MAX_KB', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | ClamAV antivirus scanning (M-8)
    |--------------------------------------------------------------------------
    |
    | Set CLAMAV_ENABLED=true in production once the ClamAV daemon is installed.
    | The job dispatches only when enabled; all other settings are ignored otherwise.
    |
    */

    'clamav' => [
        'enabled' => (bool) env('CLAMAV_ENABLED', false),
        'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

];
