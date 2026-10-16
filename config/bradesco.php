<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verified manual
    |--------------------------------------------------------------------------
    |
    | "MANUAL API DE SALDO E EXTRATO", version 2.2.2, verified against the
    | project file api_bradesco.pdf (82 pages, Bradesco, PJ accounts). Every
    | endpoint, parameter and response field below exists in that manual.
    | Anything the manual does not document (notably authentication) is
    | marked UNRESOLVED and must never be guessed.
    |
    */

    'manual_version' => '2.2.2',

    /*
    |--------------------------------------------------------------------------
    | Master switches
    |--------------------------------------------------------------------------
    |
    | `enabled` gates the whole integration. `live_enabled` is a second,
    | independent switch for real HTTP calls to the bank: fixture-based
    | diagnostics work with both switches off. Both default to false and
    | live calls additionally require an explicit operator confirmation
    | (see the diagnose command). There is no scheduled job in Phase 4A.
    |
    */

    'enabled' => (bool) env('BRADESCO_ENABLED', false),

    'live_enabled' => (bool) env('BRADESCO_LIVE_ENABLED', false),

    'environment' => env('BRADESCO_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Base URLs and endpoints (manual v2.2.2)
    |--------------------------------------------------------------------------
    */

    'base_urls' => [
        'sandbox' => env('BRADESCO_SANDBOX_BASE_URL', 'https://openapisandbox.prebanco.com.br'),
        'production' => env('BRADESCO_PRODUCTION_BASE_URL', 'https://openapi.bradesco.com.br'),
    ],

    'endpoints' => [
        'balances' => '/v1/fornecimento-saldos-contas/saldos',
        'statements' => '/v1/fornecimento-extratos-contas/extratos',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication — UNRESOLVED
    |--------------------------------------------------------------------------
    |
    | The manual documents no authentication mechanism: no OAuth flow, no
    | API keys, no mTLS, no required headers. HTTP 401/403 and backend
    | codigoRetorno 04 exist, but the credential contract is missing, so
    | live calls stay disabled until the bank supplies it. These keys
    | exist only to isolate future credentials from application code;
    | setting them changes nothing while the scheme is unknown.
    |
    */

    'auth' => [
        'scheme' => env('BRADESCO_AUTH_SCHEME'),
        'token' => env('BRADESCO_API_TOKEN'),
        'client_id' => env('BRADESCO_CLIENT_ID'),
        'client_secret' => env('BRADESCO_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Explicit connect and request timeouts on every call. There are no
    | automatic retries: a timed-out balance read must surface as an
    | error, never silently return partial data.
    |
    */

    'timeouts' => [
        'connect_seconds' => (int) env('BRADESCO_CONNECT_TIMEOUT', 5),
        'request_seconds' => (int) env('BRADESCO_REQUEST_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default tipoOperacao
    |--------------------------------------------------------------------------
    |
    | 1 = day balance, checking account only; 2 = unified day balance
    | (checking + Invest Facil). The manual marks the parameter optional
    | and the FAQ shows it changes the returned composition, so no
    | default is assumed: null omits the parameter and every report
    | records which value was actually sent.
    |
    */

    'default_tipo_operacao' => env('BRADESCO_TIPO_OPERACAO'),

    /*
    |--------------------------------------------------------------------------
    | Statement window guidance (manual v2.2.2)
    |--------------------------------------------------------------------------
    |
    | Up to 5.000 transactions per request, up to 10 years of history,
    | 1-year windows recommended. Historical period statements cover
    | D-2 backwards; D-1, D0 and future movements arrive in their own
    | dedicated sections.
    |
    */

    'statement' => [
        'max_transactions_per_request' => 5000,
        'max_history_years' => 10,
        'recommended_window_months' => 12,
    ],

];
