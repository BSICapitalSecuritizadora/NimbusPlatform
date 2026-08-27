<?php

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;

return [
    'sla' => [
        // Calendário corporativo nacional já governado pelo NimbusPlatform.
        // A avaliação falha com estado explícito se o ano ainda não estiver materializado.
        'calendar_code' => env('MEASUREMENT_SLA_CALENDAR_CODE', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS),

        // Deadlines per stage — null means NotConfigured (motor stays NotApplicable).
        // No fabricated defaults: business must explicitly configure via DB (sla_configurations) or env.
        // Example: MEASUREMENT_SLA_STAGE1_DAYS=5 etc. in .env when SLA is actually defined.
        'stage_deadlines' => [
            1 => env('MEASUREMENT_SLA_STAGE1_DAYS', null) !== null ? (int) env('MEASUREMENT_SLA_STAGE1_DAYS') : null,
            2 => env('MEASUREMENT_SLA_STAGE2_DAYS', null) !== null ? (int) env('MEASUREMENT_SLA_STAGE2_DAYS') : null,
            3 => env('MEASUREMENT_SLA_STAGE3_DAYS', null) !== null ? (int) env('MEASUREMENT_SLA_STAGE3_DAYS') : null,
            4 => env('MEASUREMENT_SLA_STAGE4_DAYS', null) !== null ? (int) env('MEASUREMENT_SLA_STAGE4_DAYS') : null,
            5 => env('MEASUREMENT_SLA_STAGE5_DAYS', null) !== null ? (int) env('MEASUREMENT_SLA_STAGE5_DAYS') : null,
        ],

        // Warning threshold — only used when SLA is configured. 75 is legacy schema default, 80 was Platform default;
        // neither is proven operational. Keep configurable, fail-safe if invalid.
        'warning_threshold_percent' => env('MEASUREMENT_SLA_WARNING_PERCENT', null) !== null ? (int) env('MEASUREMENT_SLA_WARNING_PERCENT') : null,

        // Escalation (breach) threshold — defaults to 100 (deadline) if not configured.
        'escalation_threshold_percent' => env('MEASUREMENT_SLA_ESCALATION_PERCENT', null) !== null ? (int) env('MEASUREMENT_SLA_ESCALATION_PERCENT') : 100,
    ],

    'delegations' => [
        // Warning window before expiration — 48h is legacy NimbusOps (SlaAlertService::checkExpiringDelegations default 48),
        // 72h (3 days) was Platform implementation default. Keep configurable, default 48 for parity.
        // Protected retention requires business/legal sign-off — see config/audit.php.
        'warning_hours_before' => (int) env('DELEGATION_WARNING_HOURS_BEFORE', 48),
        // Deprecated: warning_days_before kept for backwards compat, prefer warning_hours_before
        'warning_days_before' => (int) env('DELEGATION_WARNING_DAYS_BEFORE', 2),
    ],
];
