<?php

namespace App\Services\Nimbus\Migration;

class ShareholderPlanner
{
    public function plan(LegacyShareholderDto $dto, string $fingerprint, array $submissionMapBySourceId): array
    {
        $parent = $submissionMapBySourceId[(string) $dto->submissionId] ?? null;
        if ($parent === null) {
            return $this->result('INVALID_RELATION', $dto, $fingerprint, 'Parent submission not mapped', 'MISSING_PARENT');
        }
        if ($parent['status'] === 'CONFLICT' || $parent['status'] === 'INVALID_RELATION' || $parent['status'] === 'ERROR') {
            return $this->result('INVALID_RELATION', $dto, $fingerprint, 'Parent submission invalid', 'PARENT_INVALID');
        }

        return $this->result('MIGRATED', $dto, $fingerprint, 'Planned create');
    }

    private function result(string $status, LegacyShareholderDto $dto, string $fingerprint, string $disposition, ?string $errorCode = null): array
    {
        return [
            'source_entity' => 'portal_submission_shareholders',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_submission_shareholders',
            'target_id' => null,
            'target_role' => 'PRIMARY',
            'status' => $status,
            'disposition' => $disposition,
            'error_code' => $errorCode,
            'source_fingerprint' => $fingerprint,
            'metadata' => ['submission_id' => (string) $dto->submissionId],
        ];
    }
}
