<?php

namespace App\Services\Nimbus\Migration;

/**
 * Workflow history planner — only PROVEN transitions become history rows.
 * Otherwise BASELINE_ONLY.
 */
class WorkflowHistoryPlanner
{
    /**
     * @param  LegacyAuditDto[]  $auditLogsForSubmission  sorted by occurredAt
     */
    public function plan(
        LegacySubmissionDto $submission,
        array $auditLogsForSubmission,
        string $sourceSnapshotAt,
        LegacyActorResolver $actorResolver,
        array $legacyAdminRowsById = []
    ): array {
        $proven = [];

        foreach ($auditLogsForSubmission as $audit) {
            $action = strtoupper($audit->action);
            // Only transitions that explicitly encode old/new status
            // Legacy audit uses action SUBMISSION_STATUS_CHANGED or similar with details containing oldStatus/newStatus
            if (in_array($action, ['SUBMISSION_STATUS_CHANGED', 'STATUS_CHANGED', 'SUBMISSION_CREATED', 'PORTAL_SUBMISSION_CREATED', 'SUBMISSION_RECEIVED'], true)) {
                // Try to extract old/new from details
                $details = $audit->details ?? [];
                $old = $details['oldStatus'] ?? $details['old_status'] ?? $details['from'] ?? null;
                $new = $details['newStatus'] ?? $details['new_status'] ?? $details['to'] ?? $details['status'] ?? null;

                // For creation events, treat as NULL → PENDING
                if (in_array($action, ['SUBMISSION_CREATED', 'PORTAL_SUBMISSION_CREATED'], true) && $new === null) {
                    $new = 'PENDING';
                    $old = null;
                }

                // If audit is SUBMISSION_RECEIVED fallback
                if ($new === null && $audit->targetId == $submission->id) {
                    // Cannot prove status — skip as PARTIAL, not PROVEN
                    continue;
                }

                if ($new === null) {
                    continue;
                }

                // Validate statuses
                if (! in_array(strtoupper((string) $new), ['PENDING', 'UNDER_REVIEW', 'COMPLETED', 'REJECTED', 'NEEDS_CORRECTION'], true)) {
                    continue;
                }
                // Explicit NEEDS_CORRECTION synthesis forbidden unless proven log
                // So we only allow NEEDS_CORRECTION if old/new explicitly contains it.

                $resolvedActor = $actorResolver->resolve($audit->actorId, $legacyAdminRowsById[$audit->actorId ?? ''] ?? null);

                $proven[] = [
                    'old_status' => $old ? strtoupper((string) $old) : null,
                    'new_status' => strtoupper((string) $new),
                    'actor_type' => $resolvedActor['resolved_user_id'] !== null ? 'USER' : 'SYSTEM',
                    'actor_id' => $resolvedActor['resolved_user_id'],
                    'occurred_at' => $audit->occurredAt,
                    'reason' => $audit->summary ?? $audit->action,
                    'legacy_metadata' => [
                        'legacy_audit_id' => $audit->id,
                        'legacy_actor_id' => $audit->actorId,
                        'legacy_actor_type' => $audit->actorType,
                    ],
                    'provenance' => 'PROVEN',
                ];
            }
        }

        // Inferred note text must not create NEEDS_CORRECTION — so we do not scan notes here.

        if (count($proven) === 0) {
            // BASELINE_ONLY — observation at snapshot time, not at submitted_at
            return [
                [
                    'old_status' => null,
                    'new_status' => strtoupper($submission->status),
                    'actor_type' => 'SYSTEM',
                    'actor_id' => null,
                    'occurred_at' => $sourceSnapshotAt,
                    'reason' => 'Legacy import baseline — current state as of source snapshot; prior transitions not evidenced',
                    'provenance' => 'BASELINE_ONLY',
                ],
            ];
        }

        // Sort proven by occurred_at
        usort($proven, fn ($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']));

        return $proven;
    }
}
