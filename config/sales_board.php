<?php

use App\Support\SalesBoards\SalesBoardAutomationConfig;

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
    | Quem está sob automação NÃO vem daqui. A habilitação é o rollout por
    | Emissão (tela "Rollout do Quadro de Vendas"): modo automatizado,
    | competência inicial e escopo homologado, gravados na própria Emissão. Não
    | existe variável de ambiente que automatize um empreendimento -- a antiga
    | `SALES_BOARD_AUTOMATION_TARGETS` da Fase F foi removida e, se ainda estiver
    | definida em algum ambiente, é ignorada.
    |
    */

    'automation' => [

        /*
        | Interruptor geral. Desligado, o comando volta imediatamente: não
        | descobre nada, não tenta alvo nenhum, não roda lembrete e não registra
        | nem a própria execução.
        |
        | O default é `false` de propósito: as migrations desta fase podem ser
        | aplicadas muito antes de existir decisão de rollout, e um ambiente que
        | ganhasse a tabela já começaria a gerar competências sem que ninguém
        | tivesse escolhido isso. Ligar é decisão de operação, tomada quando a
        | primeira Emissão for ativada no rollout.
        |
        | Só liga com `true`, `1`, `yes` ou `on`. Qualquer outro valor -- `off`,
        | `no`, `2`, um erro de digitação -- é desligado: um interruptor de
        | segurança falha fechado.
        */
        'enabled' => SalesBoardAutomationConfig::flag(env('SALES_BOARD_AUTOMATION_ENABLED', false)),

        /*
        | Quando tentar de novo.
        |
        | Bloqueio de prontidão e falha técnica têm cadências diferentes porque
        | são problemas diferentes. Um dado faltando é resolvido por uma pessoa
        | ao longo do dia, e insistir de hora em hora só produziria vinte e
        | quatro derivações completas e nenhuma informação nova. Uma falha
        | técnica costuma ser transitória e merece voltar mais cedo, com espera
        | crescente -- contada pelas falhas técnicas consecutivas, não pelos
        | bloqueios.
        |
        | Só um inteiro positivo muda a cadência. Texto, zero ou negativo voltam
        | ao default (`24h` vira 24, não 1): um valor ilegível nunca pode fazer
        | a automação rederivar a carteira bloqueada de hora em hora.
        */
        'retry' => [
            'blocked_after_hours' => SalesBoardAutomationConfig::positiveInteger(env('SALES_BOARD_AUTOMATION_BLOCKED_RETRY_HOURS'), 24),
            'failed_backoff_hours' => [1, 2, 4, 8],
            'failed_max_backoff_hours' => SalesBoardAutomationConfig::positiveInteger(env('SALES_BOARD_AUTOMATION_FAILED_MAX_BACKOFF_HOURS'), 24),
        ],

        /*
        | Execução travada.
        |
        | Uma execução que continua "executando" depois deste prazo morreu no
        | meio (falta de memória, deploy, reinício): a execução seguinte a marca
        | como interrompida, registra como falha a tentativa que estava em
        | andamento e avisa. Nunca abaixo do lock de sobreposição do scheduler
        | (120 minutos), para não encerrar uma execução ainda viva.
        */
        'stale_run_after_minutes' => SalesBoardAutomationConfig::positiveInteger(
            env('SALES_BOARD_AUTOMATION_STALE_RUN_MINUTES'),
            SalesBoardAutomationConfig::DEFAULT_STALE_RUN_MINUTES,
        ),

        /*
        | Teto de memória da execução.
        |
        | O scheduler dispara o comando num processo PHP novo, sem as flags `-d`
        | de quem o chamou; o comando eleva o próprio `memory_limit` para este
        | valor antes de gerar. Aceita `-1` ou número com K/M/G; qualquer outra
        | coisa volta a 512M.
        */
        'memory_limit' => SalesBoardAutomationConfig::memoryLimit(env('SALES_BOARD_AUTOMATION_MEMORY_LIMIT')),

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
        |
        | Só um inteiro não negativo liga o aviso. Zero vale -- "avisar assim que
        | a condição existir" --, mas texto, decimal ou negativo desligam: um
        | limiar ilegível nunca pode virar "avisar agora".
        |
        | As sete variáveis estão documentadas, comentadas, no `.env.example`.
        | Com todas desligadas a tela de rollout avisa que ninguém receberá
        | lembrete de prazo. A execução interrompida e a suspensão por mudança de
        | escopo não dependem destes limiares: são avisadas sempre.
        */
        'reminders' => [
            'blocked_after_days' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_BLOCKED_REMINDER_DAYS')),
            'failed_after_attempts' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_FAILED_ESCALATION_ATTEMPTS')),
            'ready_for_builder_after_days' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_READY_REMINDER_DAYS')),
            'builder_review_after_days' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_BUILDER_REMINDER_DAYS')),
            'builder_review_escalation_after_days' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_BUILDER_ESCALATION_DAYS')),
            'management_review_after_days' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_MANAGEMENT_REMINDER_DAYS')),
            'management_review_escalation_after_days' => SalesBoardAutomationConfig::threshold(env('SALES_BOARD_AUTOMATION_MANAGEMENT_ESCALATION_DAYS')),
        ],
    ],

];
