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
        'job_applications' => [
            'months' => (int) env('LGPD_RETENTION_JOB_APPLICATIONS_MONTHS', 12),
        ],

        // Mensagens do formulário de contato do site.
        'contact_messages' => [
            'months' => (int) env('LGPD_RETENTION_CONTACT_MESSAGES_MONTHS', 24),
        ],

    ],

];
