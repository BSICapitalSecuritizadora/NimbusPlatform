<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sincronização de índices publicados via Banco Central (SGS)
    |--------------------------------------------------------------------------
    |
    | Fonte oficial para índices PUBLICADOS/históricos. A projeção IPCA de mercado
    | (ANBIMA/B3) NÃO vem daqui — continua sendo cadastrada/importada e aprovada
    | internamente (ver série projetada / maker-checker). Os códigos das séries
    | ficam centralizados aqui — nunca hardcoded na engine.
    |
    | Códigos SGS confirmados (https://api.bcb.gov.br/dados/serie):
    |  - CDI anualizada base 252 (% a.a.) ......... 4389  → casa direto com rate_value (taxa anual)
    |  - IPCA variação mensal (%) ................. 433   → transformado para número-índice (encadeado)
    |
    | IMPORTANTE (semântica de rate_value): a engine CDI usa TAXA ANUAL base 252 e a engine
    | IPCA usa NÚMERO-ÍNDICE. O SGS entrega o CDI já anualizado (4389), mas o IPCA apenas como
    | VARIAÇÃO MENSAL (433). Por isso o IPCA passa por uma transformação EXPLÍCITA e testada
    | (variação → número-índice, encadeada sobre o último NI persistido). O significado de
    | rate_value nunca é alterado silenciosamente.
    */

    'bcb' => [
        'base_url' => env('PU_BCB_SGS_BASE_URL', 'https://api.bcb.gov.br/dados/serie'),

        'timeout' => (int) env('PU_BCB_SGS_TIMEOUT', 15),

        'retries' => (int) env('PU_BCB_SGS_RETRIES', 2),

        'retry_sleep_ms' => (int) env('PU_BCB_SGS_RETRY_SLEEP_MS', 500),

        /**
         * Janela padrão (dias) usada quando --from/--to não são informados.
         */
        'default_window_days' => (int) env('PU_BCB_SGS_WINDOW_DAYS', 45),

        /**
         * Política de sobrescrita ao sincronizar: skip_existing | update_if_changed | overwrite.
         */
        'overwrite_policy' => env('PU_BCB_SGS_OVERWRITE_POLICY', 'update_if_changed'),

        'series' => [
            'cdi' => [
                'code' => (int) env('PU_BCB_SGS_CDI_CODE', 4389),
                // annual_rate: valor armazenado direto em rate_value (% a.a. base 252).
                'value_type' => 'annual_rate',
                'source' => 'bcb_sgs',
            ],
            'ipca' => [
                'code' => (int) env('PU_BCB_SGS_IPCA_CODE', 433),
                // monthly_variation: transformado para número-índice encadeado antes de persistir.
                'value_type' => 'monthly_variation',
                'source' => 'bcb_sgs',
            ],
        ],
    ],
];
