<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automação operacional do Quadro de Vendas
    |--------------------------------------------------------------------------
    |
    | A automação prepara a competência mensal: no dia 13 (calendário civil, no
    | fuso de negócio) a competência do mês anterior fica devida, e o scheduler
    | garante que exista exatamente um ciclo para cada empreendimento habilitado.
    |
    | Ela para aí. Validar, decidir, aprovar e publicar continuam sendo atos
    | humanos -- a automação não atravessa nenhuma dessas fronteiras.
    |
    */

    'automation' => [

        /*
        | Interruptor geral. Desligado, o comando executa, não descobre nada e
        | não escreve nada.
        |
        | O default é `false` de propósito: as migrations desta fase podem ser
        | aplicadas muito antes de existir decisão de rollout, e um ambiente que
        | ganhasse a tabela já começaria a gerar competências sem que ninguém
        | tivesse escolhido isso. Habilitar é decisão da Fase G.
        */
        'enabled' => env('SALES_BOARD_AUTOMATION_ENABLED', false),

        /*
        | Os empreendimentos habilitados, e desde que competência.
        |
        | Vazio por default, e sem nenhum id versionado: um id real aqui dentro
        | significaria que clonar o repositório já habilita a automação de um
        | empreendimento de verdade. Enquanto a Fase G não decidir o rollout por
        | Emissão, a habilitação é explícita e vem do ambiente ou de override de
        | teste.
        |
        | Cada alvo:
        |
        |   [
        |       'construction_id'          => 4,
        |       'start_reference_month'    => '2026-08-01',
        |       'auto_open_builder_review' => false,
        |   ]
        |
        | `start_reference_month` é obrigatório e é o que impede a automação de
        | varrer o histórico inteiro do empreendimento. Sem ele o alvo é
        | descartado -- ausência de data de ativação não pode virar "desde
        | sempre".
        |
        | A leitura é de uma variável de ambiente em JSON, e não de uma lista
        | escrita aqui, justamente para que habilitar um empreendimento não exija
        | commit: a decisão é de rollout, muda por ambiente, e não pertence ao
        | repositório. JSON inválido cai para lista vazia -- o mesmo default
        | inerte, porque uma configuração que ninguém consegue ler não pode
        | virar "automatize tudo".
        */
        'targets' => json_decode((string) env('SALES_BOARD_AUTOMATION_TARGETS', '[]'), true) ?: [],

        /*
        | Quando tentar de novo.
        |
        | Bloqueio de prontidão e falha técnica têm cadências diferentes porque
        | são problemas diferentes. Um dado faltando é resolvido por uma pessoa
        | ao longo do dia, e insistir de hora em hora só produziria vinte e
        | quatro derivações completas e nenhuma informação nova. Uma falha
        | técnica costuma ser transitória e merece voltar mais cedo, com espera
        | crescente.
        */
        'retry' => [
            'blocked_after_hours' => (int) env('SALES_BOARD_AUTOMATION_BLOCKED_RETRY_HOURS', 24),
            'failed_backoff_hours' => [1, 2, 4, 8],
            'failed_max_backoff_hours' => (int) env('SALES_BOARD_AUTOMATION_FAILED_MAX_BACKOFF_HOURS', 24),
        ],

        /*
        | Lembretes e escalação.
        |
        | Todos os limiares nascem `null`, e `null` significa desligado. O
        | projeto não tem SLA definido para o Quadro de Vendas, e escolher "3
        | dias" aqui seria inventar um requisito de negócio dentro de um arquivo
        | de configuração -- que é onde ninguém procuraria por ele depois.
        |
        | Dias civis corridos, não dias úteis: enquanto não existir regra de
        | negócio dizendo o contrário, contar dias úteis seria a mesma invenção.
        */
        'reminders' => [
            'blocked_after_days' => env('SALES_BOARD_AUTOMATION_BLOCKED_REMINDER_DAYS'),
            'failed_after_attempts' => env('SALES_BOARD_AUTOMATION_FAILED_ESCALATION_ATTEMPTS'),
            'ready_for_builder_after_days' => env('SALES_BOARD_AUTOMATION_READY_REMINDER_DAYS'),
            'builder_review_after_days' => env('SALES_BOARD_AUTOMATION_BUILDER_REMINDER_DAYS'),
            'builder_review_escalation_after_days' => env('SALES_BOARD_AUTOMATION_BUILDER_ESCALATION_DAYS'),
            'management_review_after_days' => env('SALES_BOARD_AUTOMATION_MANAGEMENT_REMINDER_DAYS'),
            'management_review_escalation_after_days' => env('SALES_BOARD_AUTOMATION_MANAGEMENT_ESCALATION_DAYS'),
        ],
    ],

];
