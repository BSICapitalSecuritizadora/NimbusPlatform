<?php

use App\Models\IntegralizationHistory;
use App\Models\Payment;
use App\Models\PuHistory;

return [
    // Dias de retenção para logs descartáveis (default/activity genérica)
    // Protected retention requires business/legal sign-off — see §13.
    'retention_disposable_days' => (int) env('AUDIT_RETENTION_DISPOSABLE_DAYS', 365),

    // Dias de retenção para evidências reguladas de workflow (medições/operações/delegações/Quadro de Vendas)
    // 2555 days (~7 years) is a chosen governance policy, not inherited from NimbusOps or confirmed legal requirement.
    // Protected retention requires business/legal sign-off.
    'retention_workflow_days' => (int) env('AUDIT_RETENTION_WORKFLOW_DAYS', 2555),

    // Log names considerados protegidos (não deletados antes de retention_workflow_days).
    //
    // Esta lista é a ÚNICA fonte da política: `audit:clean-filtered` a lê daqui.
    // Antes existiam duas listas -- esta e uma hardcoded no comando --, elas
    // divergiam, e só a do comando surtia efeito; foi assim que a trilha de
    // acesso a arquivo ficou declarada como protegida e mesmo assim era
    // descartada em um ano.
    //
    // Cada entrada abaixo diz quem escreve nela. Antes de acrescentar uma,
    // confirme que existe produtor:
    //   grep -rn "useLogName(" app/Models
    //   grep -rn "activity('" app | grep -o "activity('[a-z_-]*')" | sort -u
    'protected_logs' => [
        'measurement_financial_rules',
        'measurement_evidence',      // MeasurementReceiptEvidenceService — versões e decisões documentais
        'measurement_workflow',      // MeasurementWorkflow::audit() — aprovação, recusa, pausa, retomada, pagamento, comprovante, finalização
        'measurement_file_access',   // controllers de download — asset, arquivo da medição e comprovante, com sha256
        'measurements',              // Measurement (LogsActivity) — situação, etapa e demais colunas; MeasurementPlanSet, MeasurementPlanVersion e MeasurementPlanLine (LogsActivity) — plano, versões (fundo e cronograma), avanço físico inicial; MeasurementPlanVersionService::audit() — criação, ativação, substituição e cancelamento de versão
        'measurement_payments',      // MeasurementPayment (LogsActivity) — valor, data, comprovante
        'operations',                // Operation (LogsActivity) + OperationLifecycleService — transições de ciclo de vida; OperationResponsibilityService::syncRejectionRecipients() — quem é notificado em caso de recusa
        'delegations',               // ResponsibilityDelegation (LogsActivity) + criação/revogação explícitas
        'areas',                     // AreaResponsibilityService — quem responde por cada área (habilita a auto-homologação do PU)
        'nimbus',                    // portal: documentos, arquivos de submissão, tokens de acesso
        // Quadro de Vendas e as fontes que decidem os números dele. Incluídos
        // na correção da auditoria do módulo (2026-09-25): a governança de um
        // número que alimenta Garantias e Relatório mensal não pode expirar em
        // um ano, e as linhas duráveis guardam só o estado vigente.
        //
        // `sales_board` (LogsActivity): SalesBoard, SalesBoardHistory,
        // SalesBoardCycle, SalesBoardBuilderReview,
        // SalesBoardBuilderReviewSection, SalesBoardBuilderDivergence,
        // SalesBoardBuilderReviewAttachment, SalesBoardManagementReview,
        // SalesBoardManagementNonconformity, SalesBoardPublication,
        // SalesBoardCycleRectification, SalesBoardRolloutHomologation,
        // SalesBoardRolloutHomologationConstruction e SalesBoardRolloutRecipient.
        // Seções e divergências da validação da construtora entraram em
        // 2026-10-01: a divergência é apagada fisicamente no rascunho, e o que
        // ela dizia, quem a apagou e quando só sobrevivem nesta trilha. No
        // mesmo dia entraram os anexos da resposta da construtora, a evidência
        // que sustenta o envio interno da validação, e a retificação de
        // competência publicada -- quem pediu para mudar uma posição publicada,
        // por quê, e como o pedido terminou.
        'sales_board',               // quadro legado e versões, ciclo, validação, análise e decisões da Gestão, publicação, retificação, rollout
        'contracts',                 // Contract (LogsActivity) + Contract::syncBuyers() — exclusão, restauração, status e compradores
        'contract_installments',     // ContractInstallment (LogsActivity) — vencimento, pagamento, cancelamento, exclusão
        'construction_units',        // ConstructionUnit (LogsActivity) — cadastro e valor base da unidade
        'construction_unit_exchanges', // ConstructionUnitExchange (LogsActivity) — vigência das permutas
        'construction_unit_retirements', // ConstructionUnitRetirement (LogsActivity) — baixa e reativação de unidade
        'constructions',             // Construction (LogsActivity) — empreendimento e vínculo com a Emissão
        'emissions',                 // Emission (LogsActivity) — cadastro da Emissão e modo do Quadro de Vendas
        // Garantias por competência. A decisão de 25/09 pede a confirmação do
        // fechamento parcial gravada e um "Reabrir" com motivo: o número fechado
        // já saiu em relatório, e a evidência dele não pode expirar antes.
        'guarantee_competences',     // GuaranteeSnapshotWriter + GuaranteeSnapshot (LogsActivity) — valor manual, fechamento, reabertura e desatualização da competência
        'guarantees',                // Guarantee (LogsActivity) — regra contratual, elegibilidade e situação jurídica da garantia
        // Sem produtor hoje, mantidos porque já constavam da lista efetiva do
        // comando: removê-los seria estreitar a política sem decisão.
        'measurement_receipts',
        'delegation',
        // PU (Fase 6, P1-10). As duas trilhas inteiras são decisão humana de
        // governança: revisão de evidência do baseline e alteração de
        // calendário (feriado muda a contagem de dias úteis da curva).
        'pu-baseline-evidence',      // PuBaselineEvidenceReviewService — revisão e decisão sobre evidência contratual
        'business_calendars',        // B3ListedCalendarSanitationService — saneamento do calendário de sessões
    ],

    /*
    |--------------------------------------------------------------------------
    | Retenção por evento (Fase 6, P1-10)
    |--------------------------------------------------------------------------
    |
    | Uma trilha pode misturar evidência financeira e diagnóstico. `pu-calculation`
    | é assim: homologação, invalidação, correção de índice, liquidação e decisão
    | de conflito convivem com sincronização diária, exportação e falha passageira.
    | Os eventos em `protected_events` ficam com a retenção protegida
    | (`retention_workflow_days`) mesmo numa trilha descartável; os demais da
    | trilha continuam no prazo descartável. `disposable_events` não muda nada na
    | limpeza -- registra a classificação para que um evento novo não entre sem
    | decisão (o teste de retenção cobra que todo evento da trilha esteja numa das
    | duas listas).
    |
    | O prazo protegido continua sendo a política escolhida acima, pendente de
    | aprovação jurídica/compliance -- nenhum prazo legal novo é presumido aqui.
    */
    'protected_events' => [
        'pu-calculation' => [
            // Governança da curva: maker, validação, homologação, invalidação.
            'generated', 'reprocessed', 'validated', 'homologated', 'invalidated',
            // Insumos contratuais e o que mudou no passado oficial.
            'parameters_updated', 'event_changed', 'contractual_input_changed',
            'contractual_change_detected', 'curve_extension_diverged', 'curve_extended',
            // Índice: correção governada e carga manual.
            'index_rate_corrected', 'index_rates_imported',
            // Candidata, validação externa e promoção (maker/checker).
            'candidate_configuration_created', 'candidate_curve_persisted',
            'pu_candidate_curve_approved', 'pu_candidate_curve_rejected',
            'external_benchmark_imported', 'external_comparison_created',
            'pu_candidate_external_validation_validated', 'pu_candidate_external_validation_rejected',
            'pu_curve_promotion_requested', 'pu_curve_promotion_approved', 'pu_curve_promotion_rejected',
            'pu_curve_promoted_operational',
            'numeric_snapshots_prepared', 'numeric_events_prepared',
            // Liquidação, conflito e esperado de obrigação já liquidada (Fase 5).
            'settlement_recorded', 'settlement_corrected', 'settlement_reversed',
            'settlement_conflict_detected', 'settlement_conflict_resolved', 'settlement_rejected',
            'settled_obligation_calculation_changed',
            // Ação humana sobre o operacional (Fase 6).
            'obligation_refresh_retried', 'operational_incident_acknowledged',
        ],
    ],

    'disposable_events' => [
        'pu-calculation' => [
            'failed',                          // falha de geração (a versão guarda o erro)
            'exported', 'homologation_report_downloaded',
            'index_synced',                    // rotina diária; as tentativas ficam em pu_index_sync_attempts
            'curve_extension_failed',          // a versão guarda a falha vigente
            'obligations_refreshed',           // o esperado vive, imutável, nas tabelas da Fase 5
            'obligation_refresh_recovered', 'obligation_refresh_failed',
        ],
    ],

    /*
    | Mudanças de model sem `useLogName()` caem em `default` (descartável). Estes
    | três são insumo financeiro do PU -- cronograma informado, histórico de PU
    | importado e integralização -- e só a trilha guarda quem mudou o quê:
    | protegidos pelo tipo do registro, sem mudar o log name (os leitores
    | continuam lendo `default`).
    */
    'protected_subject_types' => [
        Payment::class,
        PuHistory::class,
        IntegralizationHistory::class,
    ],
];
