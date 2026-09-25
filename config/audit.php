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
        'measurement_evidence',      // MeasurementReceiptEvidenceService — versões e decisões documentais
        'measurement_workflow',      // MeasurementWorkflow::audit() — aprovação, recusa, pausa, retomada, pagamento, comprovante, finalização
        'measurement_file_access',   // controllers de download — asset, arquivo da medição e comprovante, com sha256
        'measurements',              // Measurement (LogsActivity) — situação, etapa e demais colunas
        'measurement_payments',      // MeasurementPayment (LogsActivity) — valor, data, comprovante
        'operations',                // Operation (LogsActivity) + OperationLifecycleService — transições de ciclo de vida
        'delegations',               // ResponsibilityDelegation (LogsActivity) + criação/revogação explícitas
        'nimbus',                    // portal: documentos, arquivos de submissão, tokens de acesso
        // Quadro de Vendas e as fontes que decidem os números dele. Incluídos
        // na correção da auditoria do módulo (2026-09-25): a governança de um
        // número que alimenta Garantias e Relatório mensal não pode expirar em
        // um ano, e as linhas duráveis guardam só o estado vigente.
        //
        // `sales_board` (LogsActivity): SalesBoard, SalesBoardHistory,
        // SalesBoardCycle, SalesBoardBuilderReview, SalesBoardManagementReview,
        // SalesBoardManagementNonconformity, SalesBoardPublication,
        // SalesBoardRolloutHomologation, SalesBoardRolloutHomologationConstruction
        // e SalesBoardRolloutRecipient.
        'sales_board',               // quadro legado e versões, ciclo, validação, análise e decisões da Gestão, publicação, rollout
        'contracts',                 // Contract (LogsActivity) + Contract::syncBuyers() — exclusão, restauração, status e compradores
        'contract_installments',     // ContractInstallment (LogsActivity) — vencimento, pagamento, cancelamento, exclusão
        'construction_units',        // ConstructionUnit (LogsActivity) — cadastro e valor base da unidade
        'construction_unit_exchanges', // ConstructionUnitExchange (LogsActivity) — vigência das permutas
        'constructions',             // Construction (LogsActivity) — empreendimento e vínculo com a Emissão
        'emissions',                 // Emission (LogsActivity) — cadastro da Emissão e modo do Quadro de Vendas
        // Sem produtor hoje, mantidos porque já constavam da lista efetiva do
        // comando: removê-los seria estreitar a política sem decisão.
        'measurement_receipts',
        'delegation',
    ],
];
