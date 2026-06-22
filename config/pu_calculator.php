<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monitoramento operacional da calculadora de PU
    |--------------------------------------------------------------------------
    */

    'alerts' => [
        /**
         * Destinatarios dos alertas operacionais (CSV no .env).
         * Ex.: PU_CALCULATOR_ALERT_RECIPIENTS="ops@empresa.com,risco@empresa.com"
         */
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PU_CALCULATOR_ALERT_RECIPIENTS', '')),
        ))),

        /**
         * Intervalo minimo (minutos) entre alertas para o mesmo conjunto de problemas.
         */
        'cooldown_minutes' => (int) env('PU_CALCULATOR_ALERT_COOLDOWN_MINUTES', 180),
    ],

    /**
     * Minutos para considerar uma geracao/validacao "travada" em processamento.
     */
    'stale_processing_minutes' => (int) env('PU_CALCULATOR_STALE_PROCESSING_MINUTES', 30),

    /**
     * TTL (segundos) do cache do relatorio de cobertura de CDI no dashboard.
     */
    'missing_cdi_cache_seconds' => (int) env('PU_CALCULATOR_MISSING_CDI_CACHE_SECONDS', 300),
];
