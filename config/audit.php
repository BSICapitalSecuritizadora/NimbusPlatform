<?php

return [
    // Dias de retenção para logs descartáveis (default/activity genérica)
    // Protected retention requires business/legal sign-off — see §13.
    'retention_disposable_days' => (int) env('AUDIT_RETENTION_DISPOSABLE_DAYS', 365),

    // Dias de retenção para evidências reguladas de workflow (medições/operações/delegações)
    // 2555 days (~7 years) is a chosen governance policy, not inherited from NimbusOps or confirmed legal requirement.
    // Protected retention requires business/legal sign-off.
    'retention_workflow_days' => (int) env('AUDIT_RETENTION_WORKFLOW_DAYS', 2555),

    // Log names considerados protegidos (não deletados antes de retention_workflow_days)
    // Only real, authoritative log names found in codebase are protected — no blind additions.
    // Search: grep -R "activity(" app --include="*.php" | grep "activity('"
    'protected_logs' => [
        'measurement_workflow',      // workflow stage approve/reject/pause/resume/payment/receipt/finalize
        'measurement_file_access',   // asset/receipt download, integrity download (measurement_file_access)
        'nimbus',                    // portal documents, submission files, access tokens
        'delegations',               // delegation_created/revoked (explicit log name)
        // Note: 'measurements','operations','measurement_payments' etc. are not used as log_name in codebase
        // but kept for backwards compat if ever used; the authoritative source is measurement_workflow + tables.
    ],
];
