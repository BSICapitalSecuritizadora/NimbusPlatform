<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'outlook' => [
        'tenant_id' => env('OUTLOOK_TENANT_ID'),
        'client_id' => env('OUTLOOK_CLIENT_ID'),
        'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
        'mailbox' => env('OUTLOOK_MAILBOX'),
        'auth_mode' => env('OUTLOOK_MAIL_AUTH_MODE', 'smtp_oauth'),
    ],

    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI'),
        'tenant' => env('AZURE_TENANT_ID', 'common'),
    ],

    /*
    | O modelo é configurável para permitir rollback sem deploy: os prompts de
    | extração são calibrados para um modelo específico e uma troca pode
    | degradar em silêncio (campo que vira null em vez de erro). Pine sempre uma
    | versão explícita — aliases como `gemini-flash-latest` trocam o modelo sem
    | aviso, e a extração de cláusula jurídica precisa ser reproduzível.
    |
    | `inline_max_bytes` decide entre mandar o PDF embutido em base64 e subir
    | pela File API. A API rejeita requisições acima de ~20 MB e o base64 infla
    | o arquivo em ~33%, então o teto do arquivo bruto fica em ~15 MB; 12 MB
    | deixa margem para o prompt e o overhead do JSON.
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),
        'inline_max_bytes' => (int) env('GEMINI_INLINE_MAX_BYTES', 12 * 1024 * 1024),
        'file_activation_timeout' => (int) env('GEMINI_FILE_ACTIVATION_TIMEOUT', 120),
        'obligations_min_confidence' => (float) env('GEMINI_OBLIGATIONS_MIN_CONFIDENCE', 0.6),
    ],

    /*
    | Microsoft Clarity faz gravação de sessão (session replay), não apenas
    | contagem de páginas: registra movimento de ponteiro, cliques e o conteúdo
    | da tela. Por isso ele não é carregado onde a gravação capturaria segredo,
    | dado pessoal sensível ou identificaria quem preferiu não se identificar.
    |
    | O nível de mascaramento continua sendo responsabilidade do painel do
    | Clarity (Settings › Masking deve ficar em "Strict"): o SDK não expõe API
    | para forçá-lo pelo cliente. A exclusão por rota abaixo é o controle que a
    | aplicação consegue garantir sozinha.
    */
    'clarity' => [
        'id' => env('CLARITY_PROJECT_ID'),

        'excluded_routes' => [
            // Canal de Ética: denúncias pressupõem anonimato; gravar a sessão de
            // quem denuncia anula a própria finalidade do canal.
            'site.canal-etica',

            // Telas de autenticação — a gravação alcançaria o campo de senha.
            'login',
            'register',
            'password.*',
            'two-factor.*',
            'verification.*',
            'nimbus.auth.*',
            'investor.login',

            // Áreas autenticadas: dados financeiros, documentos e dados pessoais.
            'filament.*',
            'investor.*',
            'nimbus.*',
            'operacional.*',
            'settings.*',
            'profile.edit',
            'appearance.edit',
            'dashboard',

            // Formulário de proposta: dados financeiros da empresa proponente.
            'site.proposal.continuation.*',
            'proposal.create',
        ],
    ],

    'portal' => [
        'url' => env('APP_PORTAL_URL', '/portal'),
    ],

    'contact' => [
        'email' => env('CONTACT_EMAIL', 'contato@bsicapital.com.br'),
    ],

];
