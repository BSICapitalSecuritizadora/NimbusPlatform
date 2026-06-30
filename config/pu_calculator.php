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

    /*
    |--------------------------------------------------------------------------
    | Calendario de dias uteis (cobertura B3)
    |--------------------------------------------------------------------------
    |
    | A validacao de pre-requisitos exige cobertura do calendario para todo o
    | periodo da curva. Para calendarios "auto-completaveis" (B3) as linhas
    | faltantes sao geradas automaticamente (fim de semana = nao util; dia de
    | semana = util), de forma idempotente, em vez de bloquear a geracao. A
    | derivacao NAO inclui feriados: quando relevantes, feriados B3 devem ser
    | cadastrados/importados manualmente (linha com is_business_day=false), o
    | que sobrepoe a derivacao porque o backfill nunca sobrescreve linhas
    | existentes. Para calendarios fora desta lista, datas faltantes continuam
    | bloqueando a geracao com mensagem acionavel.
    */
    'business_calendar' => [
        'auto_complete' => (bool) env('PU_CALCULATOR_CALENDAR_AUTO_COMPLETE', true),

        /**
         * Codigos de calendario gerados automaticamente (case-insensitive).
         */
        'auto_completable_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PU_CALCULATOR_CALENDAR_AUTO_COMPLETABLE_CODES', 'B3')),
        ))),
    ],
];
