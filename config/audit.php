<?php

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
    ],
];
