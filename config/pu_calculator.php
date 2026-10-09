<?php

/*
 * Inteiro operacional lido do ambiente com recusa explícita: texto inválido,
 * vazio ou abaixo do mínimo cai no padrão -- nunca em 0, que num limiar quer
 * dizer "já" (Fase 6).
 */
$operationalInt = static function (string $key, int $default, int $min = 1): int {
    $value = filter_var(env($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);

    return $value === false ? $default : (int) $value;
};

/*
 * Lista de segundos (CSV) com o mesmo cuidado: qualquer item inválido descarta a
 * lista inteira e vale o padrão.
 *
 * @return list<int>
 */
$operationalSeconds = static function (string $key, array $default): array {
    $raw = env($key);

    if (! is_string($raw) || trim($raw) === '') {
        return $default;
    }

    $values = [];

    foreach (explode(',', $raw) as $item) {
        $value = filter_var(trim($item), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($value === false) {
            return $default;
        }

        $values[] = (int) $value;
    }

    return $values;
};

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

    /*
    |--------------------------------------------------------------------------
    | Monitoramento operacional (Fase 6)
    |--------------------------------------------------------------------------
    |
    | Parâmetros OPERACIONAIS do monitor -- quando uma condição já registrada
    | passa a pedir atenção --, não prazo de negócio nem regra contratual. A
    | urgência padrão de cada condição está no catálogo
    | (PuOperationalConditionType::defaultSeverity()); `severity_overrides`
    | troca a de um tipo (valor: info|warning|critical).
    */
    'monitoring' => [
        'severity_overrides' => [],

        /** Falhas seguidas da extensão oficial que tornam o incidente crítico. */
        'extension_failure_critical_after' => $operationalInt('PU_MONITOR_EXTENSION_FAILURE_CRITICAL_AFTER', 3),

        /** Falhas seguidas da sincronização de um índice que tornam o incidente crítico. */
        'index_sync_failure_critical_after' => $operationalInt('PU_MONITOR_INDEX_SYNC_FAILURE_CRITICAL_AFTER', 3),

        /** Minutos que a oficial pode ficar atrasada com índice já gravado antes de pedir atenção. */
        'stale_grace_minutes' => $operationalInt('PU_MONITOR_STALE_GRACE_MINUTES', 360),

        /** Minutos depois da divulgação esperada sem nenhuma tentativa de sincronização. */
        'index_sync_overdue_minutes' => $operationalInt('PU_MONITOR_INDEX_SYNC_OVERDUE_MINUTES', 180),

        /** Minutos que um pedido de atualização pode ficar sem execução antes de contar como parado. */
        'refresh_stalled_after_minutes' => $operationalInt('PU_MONITOR_REFRESH_STALLED_AFTER_MINUTES', 30),

        /** Minutos sem uma execução completa do monitor antes de o próprio monitor virar problema. */
        'monitor_stale_after_minutes' => $operationalInt('PU_MONITOR_STALE_AFTER_MINUTES', 60),

        /** Dias de histórico das execuções do monitor (registro de diagnóstico). */
        'run_retention_days' => $operationalInt('PU_MONITOR_RUN_RETENTION_DAYS', 30),

        /** Urgência mínima para avisar no sino do painel: warning|critical. */
        'notify_min_severity' => in_array(env('PU_MONITOR_NOTIFY_MIN_SEVERITY'), ['warning', 'critical'], true)
            ? (string) env('PU_MONITOR_NOTIFY_MIN_SEVERITY')
            : 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Atualização durável das obrigações (Fase 6)
    |--------------------------------------------------------------------------
    |
    | O pedido nasce na transação do fato; a primeira tentativa roda logo depois
    | do commit, e a varredura (`pu:obligations:recover`) retoma o que ficou para
    | trás. Falha passageira repete com espera crescente até `max_attempts`;
    | falha permanente não repete. `lease_seconds` é quanto uma execução pode
    | segurar o pedido antes de ele ser considerado interrompido.
    */
    'obligation_refresh' => [
        'max_attempts' => $operationalInt('PU_OBLIGATION_REFRESH_MAX_ATTEMPTS', 5),
        'backoff_seconds' => $operationalSeconds('PU_OBLIGATION_REFRESH_BACKOFF_SECONDS', [60, 300, 900, 3600]),
        'lease_seconds' => $operationalInt('PU_OBLIGATION_REFRESH_LEASE_SECONDS', 900, 60),
        'scanner_grace_seconds' => $operationalInt('PU_OBLIGATION_REFRESH_SCANNER_GRACE_SECONDS', 60, 0),
        'scanner_batch_size' => $operationalInt('PU_OBLIGATION_REFRESH_SCANNER_BATCH_SIZE', 50),

        /** Dias que um pedido já atendido fica como diagnóstico (os abertos nunca são apagados). */
        'completed_retention_days' => $operationalInt('PU_OBLIGATION_REFRESH_COMPLETED_RETENTION_DAYS', 90),
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
    | Calendario de dias uteis
    |--------------------------------------------------------------------------
    |
    | A validacao de pre-requisitos exige cobertura do calendario para todo o
    | periodo da curva. O codigo B3 permanece como alias legado, sem
    | redirecionamento automatico, ate a revisao contratual dos consumidores.
    | BR_BANKING_ANBIMA representa calendario bancario; B3_LISTED_TRADING,
    | sessoes de negociacao. Para calendarios "auto-completaveis" (B3 legado) as linhas
    | faltantes sao geradas automaticamente (fim de semana = nao util; dia de
    | semana = util), de forma idempotente, em vez de bloquear a geracao. A
    | derivacao NAO inclui feriados: quando relevantes, feriados B3 devem ser
    | cadastrados/importados (linha com is_business_day=false), o
    | que sobrepoe a derivacao porque o backfill nunca sobrescreve linhas
    | existentes. Para calendarios fora desta lista, datas faltantes continuam
    | bloqueando a geracao com mensagem acionavel.
    */
    'business_calendar' => [
        'auto_complete' => (bool) env('PU_CALCULATOR_CALENDAR_AUTO_COMPLETE', true),
        'lock_wait_seconds' => (int) env('PU_CALCULATOR_CALENDAR_LOCK_WAIT_SECONDS', 15),

        /**
         * Codigos de calendario gerados automaticamente (case-insensitive).
         */
        'auto_completable_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PU_CALCULATOR_CALENDAR_AUTO_COMPLETABLE_CODES', 'B3')),
        ))),
    ],
];
