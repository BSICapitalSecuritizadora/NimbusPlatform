<?php

use Illuminate\Support\Env;

/*
|--------------------------------------------------------------------------
| Antivirus flag parsing
|--------------------------------------------------------------------------
|
| `(bool) env(...)` aceita qualquer string não vazia como `true`: `disable`,
| `off` e `no` LIGARIAM a varredura em vez de desligá-la, silenciosamente. Como
| o valor decide se os uploads são varridos, um erro de digitação aqui não pode
| virar um padrão implícito — ele falha no boot (e no `config:cache`, portanto
| no deploy) com a mensagem dizendo o que escrever.
|
*/

$parseAntivirusFlag = static function (bool $default): bool {
    $configured = Env::get('CLAMAV_ENABLED');

    if ($configured === null || $configured === '') {
        return $default;
    }

    if (is_bool($configured)) {
        return $configured;
    }

    $parsed = filter_var($configured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($parsed === null) {
        throw new RuntimeException(
            "CLAMAV_ENABLED='{$configured}' não é um booleano válido. Use true ou false — "
            .'valores como "enable", "disable" ou "off" seriam interpretados como TRUE.'
        );
    }

    return $parsed;
};

$proposalContinuationMaxKb = Env::get('UPLOAD_PROPOSAL_MAX_KB');

if ($proposalContinuationMaxKb === null || $proposalContinuationMaxKb === '') {
    $legacyProposalMaxBytes = (int) Env::get('UPLOAD_PROPOSAL_MAX_BYTES', 20 * 1024 * 1024);
    $proposalContinuationMaxKb = (int) ceil($legacyProposalMaxBytes / 1024);
}

$proposalContinuationMaxKb = (int) $proposalContinuationMaxKb;

return [

    /*
    |--------------------------------------------------------------------------
    | Per-context upload limits
    |--------------------------------------------------------------------------
    |
    | All *_max_kb values are in kilobytes (for Filament / Laravel validation).
    | All *_max_bytes values are in bytes (for manual validation in Actions).
    |
    */

    'proposal_continuation' => [
        'max_kb' => $proposalContinuationMaxKb,
        'max_bytes' => $proposalContinuationMaxKb * 1024,
        'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'],
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    'admin_response' => [
        'max_kb' => (int) env('UPLOAD_ADMIN_RESPONSE_MAX_KB', 102400),
    ],

    'document' => [
        'max_kb' => (int) env('UPLOAD_DOCUMENT_MAX_KB', 102400),
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cadastro de documentos em lote
    |--------------------------------------------------------------------------
    |
    | O Livewire envia todos os arquivos de um `FileUpload` múltiplo numa única
    | requisição, então o lote inteiro trafega de uma vez: os limites abaixo
    | precisam caber no que o nginx e o PHP aceitam por requisição, e não apenas
    | no que cada documento individual aceita.
    |
    | - `max_files` = 20 acompanha o padrão do PHP `max_file_uploads`; acima
    |   disso o PHP descarta os excedentes em silêncio.
    | - `max_kb` (por arquivo) é deliberadamente menor que o do cadastro
    |   individual (`uploads.document.max_kb`): 20 arquivos no limite individual
    |   estourariam qualquer `client_max_body_size` razoável.
    | - `total_max_kb` = 80 MB deixa folga sob o `NGINX_CLIENT_MAX_BODY_SIZE`
    |   padrão do startup.sh (110 MB) para o overhead do multipart e o restante
    |   do formulário.
    | - `time_budget_seconds` limita o tempo de processamento no servidor. A
    |   varredura antivírus é síncrona (`ScansUploadedFile`) e tem timeout
    |   próprio por arquivo, então um clamd travado multiplicaria esse timeout
    |   pelo número de arquivos. O padrão fica abaixo do menor teto da
    |   requisição — `max_execution_time = 120` do `public/.user.ini`, mais
    |   apertado que o `fastcgi_read_timeout` (420s) do startup.sh — para que a
    |   degradação seja sempre a nossa, com resumo e reenvio, e nunca a do PHP,
    |   que mataria a requisição sem resposta. Ao esgotar o orçamento, os
    |   arquivos restantes são reportados como não processados e podem ser
    |   reenviados pela própria tela.
    |
    | Nenhum destes valores precisa ser definido no ambiente: os padrões já cabem
    | na configuração de produção. `max_files` não pode passar de 20 sem alterar
    | o `max_file_uploads` do PHP, que é PHP_INI_SYSTEM e não aceita override em
    | `.user.ini`.
    |
    */

    'document_batch' => [
        'max_files' => (int) env('UPLOAD_DOCUMENT_BATCH_MAX_FILES', 20),
        'max_kb' => (int) env('UPLOAD_DOCUMENT_BATCH_MAX_KB', 25600),
        'total_max_kb' => (int) env('UPLOAD_DOCUMENT_BATCH_TOTAL_MAX_KB', 81920),
        'time_budget_seconds' => (int) env('UPLOAD_DOCUMENT_BATCH_TIME_BUDGET_SECONDS', 90),
    ],

    'receivables_import' => [
        'allowed_mimes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
    ],

    'measurement' => [
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
    ],

    'measurement_receipt' => [
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
    ],

    'logo' => [
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],

    'submission' => [
        'max_kb' => (int) env('UPLOAD_SUBMISSION_MAX_KB', 51200),
        'total_max_bytes' => (int) env('UPLOAD_SUBMISSION_TOTAL_MAX_BYTES', 50 * 1024 * 1024),
    ],

    'resume' => [
        'max_kb' => (int) env('UPLOAD_RESUME_MAX_KB', 10240),
    ],

    'obligation_evidence' => [
        'max_kb' => (int) env('UPLOAD_OBLIGATION_EVIDENCE_MAX_KB', 20480),
        'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg'],
        'allowed_mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain',
            'image/png',
            'image/jpeg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ClamAV antivirus scanning (M-8)
    |--------------------------------------------------------------------------
    |
    | Scanning is enabled by default in production and can be explicitly disabled
    | in local environments. Files are not released until clamd reports them clean.
    |
    | Em produção o clamd é um container sidecar do Azure App Service. Sidecars
    | compartilham o namespace de rede do container principal e NÃO recebem DNS
    | por nome — o nome no portal é só identificador de deployment. Por isso o
    | endereço é sempre `127.0.0.1`; um hostname simbólico não resolve.
    |
    | O `socket` só é usado quando explicitamente definido. O default precisa ser
    | falsy: o ClamAvFileScanner prefere o socket unix sempre que ele existir e,
    | com um caminho como padrão, uma variável ausente faria o TCP nunca ser
    | tentado — antivírus "indisponível" com o sidecar rodando ao lado.
    |
    */

    'clamav' => [
        'enabled' => $parseAntivirusFlag(env('APP_ENV') === 'production'),
        'socket' => env('CLAMAV_SOCKET') ?: null,
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

];
