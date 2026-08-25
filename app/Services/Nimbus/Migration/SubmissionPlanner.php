<?php

namespace App\Services\Nimbus\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Submission planner with status/reference/relation handling.
 */
class SubmissionPlanner
{
    private const VALID_STATUSES = ['PENDING', 'UNDER_REVIEW', 'COMPLETED', 'REJECTED', 'NEEDS_CORRECTION'];

    public function plan(
        LegacySubmissionDto $dto,
        string $fingerprint,
        array $userMapBySourceId, // source portal_user_id → planned target id or null
        array $existingMapsBySource,
        string $sourceSnapshotAt,
    ): array {
        // Validate user relation
        $mappedUser = $userMapBySourceId[(string) $dto->portalUserId] ?? null;
        if ($mappedUser === null) {
            // Check if user exists in DB via cross-check? For dry-run we treat mapped as planned.
            // If not in plan map → INVALID_RELATION
            return $this->result('INVALID_RELATION', $dto, $fingerprint, 'Parent portal_user not mapped', 'MISSING_PARENT');
        }

        // Reference code collision: source intra-dataset
        // Handled at report aggregation level (duplicate reference_code in source), but also here:
        // Target collision
        $targetExisting = DB::table('nimbus_submissions')->where('reference_code', trim($dto->referenceCode))->first();
        if ($targetExisting) {
            return $this->result('CONFLICT', $dto, $fingerprint, 'Reference code collides with existing target', 'REFERENCE_CODE_COLLISION', $targetExisting->id);
        }

        // Status mapping: only known legacy statuses
        $status = strtoupper($dto->status);
        if (! in_array($status, ['PENDING', 'UNDER_REVIEW', 'COMPLETED', 'REJECTED'], true)) {
            // Unknown status like NEEDS_CORRECTION in future would need review
            return $this->result('CONFLICT', $dto, $fingerprint, "Unexpected legacy status {$status}", 'UNEXPECTED_STATUS');
        }

        // Idempotency
        $key = (string) $dto->id;
        if (isset($existingMapsBySource[$key])) {
            $existing = $existingMapsBySource[$key];
            if ($existing['source_fingerprint'] === $fingerprint) {
                return $this->result('ALREADY_MIGRATED', $dto, $fingerprint, 'Already mapped');
            }

            return $this->result('CONFLICT', $dto, $fingerprint, 'Fingerprint changed', 'CHANGED_SOURCE');
        }

        return $this->result('MIGRATED', $dto, $fingerprint, 'Planned create');
    }

    private function result(string $status, LegacySubmissionDto $dto, string $fingerprint, string $disposition, ?string $errorCode = null, ?int $targetId = null): array
    {
        return [
            'source_entity' => 'portal_submissions',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_submissions',
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'target_role' => 'PRIMARY',
            'status' => $status,
            'disposition' => $disposition,
            'error_code' => $errorCode,
            'source_fingerprint' => $fingerprint,
            'metadata' => [
                'reference_code' => $dto->referenceCode,
                'status' => $dto->status,
                'portal_user_id' => (string) $dto->portalUserId,
            ],
        ];
    }
}
