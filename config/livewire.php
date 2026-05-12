<?php

return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => [
            'required',
            'file',
            'max:'.(int) env('LIVEWIRE_TEMPORARY_UPLOAD_MAX_KB', 102400),
        ],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => (int) env('LIVEWIRE_TEMPORARY_MAX_UPLOAD_TIME', 15),
        'cleanup' => true,
    ],

    'csp_safe' => false,
];
