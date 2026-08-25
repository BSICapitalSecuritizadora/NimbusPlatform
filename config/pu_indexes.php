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
    |  - CDI diário/fator (% a.d.) ................ 12    → NÃO usar: a engine espera taxa ANUAL base 252
    |  - IPCA geral variação mensal (%) ........... 433   → transformado para número-índice (encadeado)
    |  - IPCA Serviços variação mensal (%) ........ 10844 → alternativa configurável (mesma transformação)
    |
    | IMPORTANTE (semântica de rate_value): a engine CDI (DailyFactorCalculator::factorDiForDay)
    | recebe a TAXA ANUAL base 252 e calcula (1 + taxa/100)^(1/252). Por isso a série CDI DEVE ser a
    | 4389 (anualizada base 252) — a série 12 (CDI diário, % a.d.) tem semântica distinta e quebraria a
    | engine. A engine IPCA usa NÚMERO-ÍNDICE; o SGS entrega o IPCA apenas como VARIAÇÃO MENSAL (433/
    | 10844), então passa por transformação EXPLÍCITA e testada (variação → número-índice, encadeada
    | sobre o último NI persistido). O significado de rate_value nunca é alterado silenciosamente.
    |
    | A consulta de séries DIÁRIAS de 10 anos numa única requisição estoura o timeout do SGS; por isso
    | a janela é dividida em blocos (chunk_months) consultados sequencialmente, com retry/backoff por
    | bloco. Os resultados são consolidados e deduplicados por data — falha em um bloco não derruba os
    | demais nem a aplicação.
    */

    'bcb' => [
        'base_url' => env('PU_BCB_SGS_BASE_URL', 'https://api.bcb.gov.br/dados/serie'),

        'connect_timeout' => (int) env('PU_BCB_SGS_CONNECT_TIMEOUT', 10),

        'timeout' => (int) env('PU_BCB_SGS_TIMEOUT', 30),

        'retries' => (int) env('PU_BCB_SGS_RETRIES', 3),

        /**
         * Sleep BASE do backoff exponencial entre tentativas (ms): tentativa N dorme
         * retry_sleep_ms * 2^(N-1). Ex.: 500 → 500ms, 1s, 2s.
         */
        'retry_sleep_ms' => (int) env('PU_BCB_SGS_RETRY_SLEEP_MS', 500),

        /**
         * Tamanho de cada bloco (em meses) ao dividir a janela consultada.
         *
         * Séries diárias (CDI) de 10 anos numa única chamada estouram o timeout do SGS. A janela é
         * dividida em blocos contíguos de `chunk_months` meses, consultados em sequência (cada um com
         * retry/backoff) e consolidados. Default 12 (anual). Use 6 para semestral em redes mais lentas.
         */
        'chunk_months' => (int) env('PU_BCB_SGS_CHUNK_MONTHS', 12),

        /**
         * Janela (anos) consultada por padrão e LIMITE MÁXIMO total.
         *
         * A rotina sempre consulta os últimos 10 anos e qualquer intervalo informado é "clampado" para
         * no máximo esse limite. Internamente a janela é dividida em blocos (ver `chunk_months`).
         */
        'window_years' => (int) env('PU_BCB_SGS_WINDOW_YEARS', 10),

        /**
         * Política de sobrescrita ao sincronizar: skip_existing | update_if_changed | overwrite.
         *
         * Default `skip_existing` (insert-only): o valor só é salvo se ainda não existir registro para a
         * data; nunca duplica nem sobrescreve. Para atualizar valores revisados, use `--force`
         * (overwrite) ou configure `update_if_changed` via env (regra específica de atualização).
         */
        'overwrite_policy' => env('PU_BCB_SGS_OVERWRITE_POLICY', 'skip_existing'),

        'series' => [
            'cdi' => [
                // 4389 = CDI anualizado base 252 (% a.a.). NÃO trocar por 12 (CDI diário): a engine
                // espera taxa anual base 252. Configurável apenas para casos validados.
                'code' => (int) env('PU_BCB_SGS_CDI_CODE', 4389),
                // annual_rate: valor armazenado direto em rate_value (% a.a. base 252).
                'value_type' => 'annual_rate',
                'source' => 'bcb_sgs',
            ],
            'ipca' => [
                // 433 = IPCA cheio/headline (variação mensal). Para IPCA Serviços use 10844 via env —
                // mesma transformação variação → número-índice.
                'code' => (int) env('PU_BCB_SGS_IPCA_CODE', 433),
                // monthly_variation: transformado para número-índice encadeado antes de persistir.
                'value_type' => 'monthly_variation',
                'source' => 'bcb_sgs',
                /**
                 * Número-índice base criado AUTOMATICAMENTE quando ainda não existe nenhuma âncora de
                 * IPCA, para que a sincronização nunca bloqueie. Como a engine de curva usa apenas
                 * RAZÕES (NI_ref / NI_anterior), a base é arbitrária e não afeta a correção monetária.
                 */
                'anchor_base' => env('PU_BCB_SGS_IPCA_ANCHOR_BASE', '100'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Homologação Taxa DI B3 × BCB SGS 4389
    |--------------------------------------------------------------------------
    |
    | O FTP MediaCDI é a distribuição estruturada oficial da B3. Cada arquivo
    | YYYYMMDD.txt contém a Taxa DI daquela data em nove dígitos, com duas casas
    | decimais implícitas. Esta configuração é usada somente pela homologação;
    | não participa da engine e não persiste em index_rates.
    |
    */

    'b3_di' => [
        'host' => env('PU_B3_DI_FTP_HOST', 'ftp.cetip.com.br'),
        'port' => (int) env('PU_B3_DI_FTP_PORT', 21),
        'directory' => env('PU_B3_DI_FTP_DIRECTORY', '/MediaCDI'),
        'timeout' => (int) env('PU_B3_DI_FTP_TIMEOUT', 30),
        'passive' => true,
        'username' => env('PU_B3_DI_FTP_USERNAME', 'anonymous'),
        'password' => env('PU_B3_DI_FTP_PASSWORD', 'nimbusplatform@localhost'),
        'documentation_url' => 'https://www.b3.com.br/pt_br/market-data-e-indices/indices/indices-de-segmentos-e-setoriais/dados-via-ftp.htm',
        'methodology_url' => 'https://www.b3.com.br/pt_br/market-data-e-indices/indices/indices-de-segmentos-e-setoriais/di/metodologia-de-apuracao-da-taxa/',
    ],

    'source_homologation' => [
        'artifact_disk' => env('PU_SOURCE_HOMOLOGATION_DISK', 'local'),
        'artifact_directory' => 'homologations/index-rate-sources',
        'minimum_common_records' => (int) env('PU_SOURCE_HOMOLOGATION_MINIMUM_COMMON_RECORDS', 250),
    ],
];
