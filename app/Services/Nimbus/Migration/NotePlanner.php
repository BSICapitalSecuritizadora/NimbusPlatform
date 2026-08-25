<?php

namespace App\Services\Nimbus\Migration;

class NotePlanner
{
    public function plan(LegacyNoteDto $dto, string $fingerprint, array $submissionMapBySourceId): array
    {
        $parent = $submissionMapBySourceId[(string) $dto->submissionId] ?? null;
        if ($parent === null) {
            return $this->result('INVALID_RELATION', $dto, $fingerprint, 'Parent submission not mapped', 'MISSING_PARENT');
        }
        if (! in_array($dto->visibility, ['USER_VISIBLE', 'ADMIN_ONLY'], true)) {
            return $this->result('CONFLICT', $dto, $fingerprint, 'Unknown visibility', 'UNEXPECTED_VISIBILITY');
        }

        return $this->result('MIGRATED', $dto, $fingerprint, 'Planned create');
    }

    private function result(string $status, LegacyNoteDto $dto, string $fingerprint, string $disposition, ?string $errorCode = null): array
    {
        return [
            'source_entity' => 'portal_submission_notes',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_submission_notes',
            'target_id' => null,
            'target_role' => 'PRIMARY',
            'status' => $status,
            'disposition' => $disposition,
            'error_code' => $errorCode,
            'source_fingerprint' => $fingerprint,
            'metadata' => ['visibility' => $dto->visibility, 'submission_id' => (string) $dto->submissionId],
        ];
    }
}
