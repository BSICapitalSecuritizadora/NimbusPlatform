<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notificações de vencimento de obrigações
    |--------------------------------------------------------------------------
    |
    | Marcos (em dias) que disparam alertas de vencimento por e-mail, além dos
    | alertas de vencimento no dia e de obrigação vencida. Ajuste por ambiente
    | sem mudar código.
    |
    */

    'notifications' => [
        'fallback_email' => env('OBLIGATIONS_NOTIFICATIONS_FALLBACK_EMAIL'),

        'due_soon_days' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('OBLIGATIONS_NOTIFICATIONS_DUE_SOON_DAYS', '7,3')),
        ))),

        'notify_due_today' => (bool) env('OBLIGATIONS_NOTIFICATIONS_NOTIFY_DUE_TODAY', true),

        'notify_overdue' => (bool) env('OBLIGATIONS_NOTIFICATIONS_NOTIFY_OVERDUE', true),

        'max_per_run' => (int) env('OBLIGATIONS_NOTIFICATIONS_MAX_PER_RUN', 200),
    ],

];
