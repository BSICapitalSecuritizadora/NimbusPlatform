<?php

return [
    'client_id' => env('CONTA_AZUL_CLIENT_ID'),
    'client_secret' => env('CONTA_AZUL_CLIENT_SECRET'),
    'redirect_uri' => env('CONTA_AZUL_REDIRECT_URI', 'https://contaazul.com'),

    'auth_url' => 'https://auth.contaazul.com/oauth2/authorize',
    'token_url' => 'https://auth.contaazul.com/oauth2/token',
    'base_url' => 'https://api-v2.contaazul.com',
    'scope' => 'openid profile aws.cognito.signin.user.admin',

    'sync_lookback_days' => (int) env('CONTA_AZUL_SYNC_LOOKBACK_DAYS', 60),
    'sync_start_date' => env('CONTA_AZUL_SYNC_START_DATE', '2025-01-01'),

    // Phase 3B: minutes of heartbeat silence after which a `running` import
    // batch counts as abandoned work (e.g. the process died to SIGKILL)
    // rather than an active import. Recovery stays explicitly invoked —
    // Filament action or Artisan command — and refuses anything fresher.
    'stale_batch_timeout_minutes' => (int) env('CONTA_AZUL_STALE_BATCH_TIMEOUT_MINUTES', 60),

    'category_map' => [
        'AGENTE FIDUCIARIO' => 'Agente Fiduciário',
        'ASSEMBLEIA' => 'AGT',
        'ASSESSOR JURIDICO' => 'Assessor Jurídico',
        'AUDITORIA' => 'Auditoria',
        'BLOQUEIO JUDICIAL' => 'Bloqueio Judicial',
        'Cartório' => 'Cartório',
        'CETIP' => 'Cetip',
        'Condomínio' => 'Condomínio',
        'CONTABILIDADE' => 'Contabilidade',
        'COORDENADOR LIDER' => 'Coordenador Líder',
        'Correios' => 'Correios',
        'CUSTODIA' => 'Custódia da CCI',
        'HORAS COMPLEMENTARES' => 'Horas Complementares',
        'ENGENHARIA' => 'Engenharia',
        'ESCRITURADOR' => 'Escriturador',
        'FEE' => 'Fee - Securitizadora',
        'IPTU' => 'IPTU',
        'PATRIMONIO SEPARADO' => 'Patrimônio Separado',
        'DESPESA PATRIMONIO SEPARADO' => 'Patrimônio Separado',
        'SERVICE' => 'Service',
        'SISTEMA' => 'Sistema',
        'TAXA ANBIMA' => 'Taxa Anbima',
        'TAXA CVM' => 'Taxa CVM',
    ],
];
