<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retenção de dados pessoais (LGPD art. 15 e 16)
    |--------------------------------------------------------------------------
    |
    | Dado pessoal coletado por formulário público não pode ser guardado
    | indefinidamente: terminado o tratamento que justificou a coleta, ele deve
    | ser eliminado. Os prazos abaixo são o gatilho automático dessa eliminação.
    |
    | Os valores padrão são um ponto de partida conservador e devem ser
    | confirmados com o jurídico — prazo de guarda é decisão de negócio, não
    | técnica. Zero ou negativo desliga o expurgo daquela base.
    |
    */

    'retention' => [

        // Currículos e dados de candidatos. Conta a partir do envio da
        // candidatura, não da última movimentação no processo seletivo.
        // Pode ser sobrescrito por `retention_overrides` para casos terminais.
        'job_applications' => [
            'months' => (int) env('LGPD_RETENTION_JOB_APPLICATIONS_MONTHS', 12),
        ],

        // Overrides por status terminal (ex.: contratados podem ter guarda maior
        // por obrigação legal/trabalhista). Quando null, usa o prazo padrão.
        'retention_overrides' => [
            'job_applications' => [
                'contratada' => env('LGPD_RETENTION_JOB_APPLICATIONS_HIRED_MONTHS') !== null
                    ? (int) env('LGPD_RETENTION_JOB_APPLICATIONS_HIRED_MONTHS')
                    : null,
                'reprovada' => env('LGPD_RETENTION_JOB_APPLICATIONS_REJECTED_MONTHS') !== null
                    ? (int) env('LGPD_RETENTION_JOB_APPLICATIONS_REJECTED_MONTHS')
                    : null,
            ],
        ],

        // Mensagens do formulário de contato do site.
        'contact_messages' => [
            'months' => (int) env('LGPD_RETENTION_CONTACT_MESSAGES_MONTHS', 24),
        ],

    ],

    'recruitment' => [
        // Janela para bloqueio de candidatura duplicada (mesmo e-mail + mesma vaga).
        // 0 desativa o bloqueio.
        'duplicate_window_days' => (int) env('RECRUITMENT_DUPLICATE_WINDOW_DAYS', 30),
    ],

];
