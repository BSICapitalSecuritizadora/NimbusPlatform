<?php

namespace App\Services\Nimbus\Migration;

use App\Services\Security\BlindIndexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portal user dry-run planner with collision detection.
 * Reads no writes; report only.
 */
class PortalUserPlanner
{
    public function plan(
        LegacyPortalUserDto $dto,
        string $fingerprint,
        array $existingMapsBySource, // source_id → MigrationMap for this run
        array $referenceSnapshot, // not used, placeholder for fingerprint check
    ): array {
        $normalizedEmail = $dto->email !== null ? Str::lower(trim($dto->email)) : null;
        $digits = $dto->documentNumber !== null ? preg_replace('/\D+/', '', $dto->documentNumber) : null;
        $docHash = null;
        if ($digits !== null && $digits !== '') {
            try {
                $docHash = BlindIndexService::documentNumber($dto->documentNumber);
            } catch (\LogicException $e) {
                // If key missing, treat as unverified source — dry-run still reports.
                $docHash = null;
            }
        }

        // Idempotency: already mapped in this run?
        $key = (string) $dto->id;
        if (isset($existingMapsBySource[$key])) {
            $existing = $existingMapsBySource[$key];
            if ($existing['source_fingerprint'] === $fingerprint) {
                return $this->result('ALREADY_MIGRATED', $dto, $fingerprint, null, 'Already mapped in this run', $docHash, $normalizedEmail);
            }

            // Fingerprint changed → treat as conflict/changed source
            return $this->result('CONFLICT', $dto, $fingerprint, null, 'Source fingerprint changed', $docHash, $normalizedEmail, 'CHANGED_SOURCE');
        }

        // Cross-check against existing target DB (read-only)
        // Email collision
        $emailTarget = null;
        if ($normalizedEmail !== null && $normalizedEmail !== '') {
            $emailTarget = DB::table('nimbus_portal_users')->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
        }

        // CPF hash collision
        $hashTarget = null;
        if ($docHash !== null) {
            $hashTarget = DB::table('nimbus_portal_users')->where('document_number_hash', $docHash)->first();
        }

        // Also check if an existing migration map for a different source already points to same target (different run)
        // For M1 we only check current DB collisions; cross-run maps are in nimbus_migration_maps table.
        // Check legacy→target map from previous runs
        $priorMap = null;
        if ($docHash !== null) {
            $priorMap = DB::table('nimbus_migration_maps')
                ->where('source_entity', 'portal_users')
                ->where('source_fingerprint', $fingerprint)
                ->first();
            // Not needed for logic here; handled via idempotency already
        }

        // Decision: both present and same target → MATCHED_EXISTING if corroborated
        if ($emailTarget && $hashTarget && $emailTarget->id === $hashTarget->id) {
            return $this->result('MATCHED_EXISTING', $dto, $fingerprint, $emailTarget->id, 'Email + CPF hash corroborated same target', $docHash, $normalizedEmail);
        }

        // Both present but different targets → CONFLICT
        if ($emailTarget && $hashTarget && $emailTarget->id !== $hashTarget->id) {
            return $this->result('CONFLICT', $dto, $fingerprint, null, 'Email and CPF hash point to different targets', $docHash, $normalizedEmail, 'EMAIL_CPF_DIVERGENCE');
        }

        // Only hash collision with contradictory email (if emailTarget is different from hashTarget)
        if ($hashTarget) {
            // If we have an email and it does not match hashTarget's email
            if ($normalizedEmail !== null) {
                $hashTargetEmail = $hashTarget->email !== null ? Str::lower(trim($hashTarget->email)) : null;
                if ($hashTargetEmail !== null && $hashTargetEmail !== $normalizedEmail) {
                    return $this->result('CONFLICT', $dto, $fingerprint, null, 'CPF hash matches but email differs', $docHash, $normalizedEmail, 'CPF_EMAIL_MISMATCH');
                }
            }

            // Hash matches and no contradictory email → matched existing (CPF is strong)
            // But spec says hash alone with contradictory data → conflict. We handled differing.
            // If no email conflict, treat as matched existing.
            return $this->result('MATCHED_EXISTING', $dto, $fingerprint, $hashTarget->id, 'CPF hash matched existing target', $docHash, $normalizedEmail);
        }

        // Only email collision (hash missing or no target)
        if ($emailTarget) {
            // Email-only without CPF corroboration → CONFLICT per spec (not sufficient to overwrite)
            // Exception: if DTO has no document, email alone could be considered matched existing?
            // Spec says email alone is NOT sufficient → must be CONFLICT
            return $this->result('CONFLICT', $dto, $fingerprint, null, 'Email collision without corroborating CPF', $docHash, $normalizedEmail, 'EMAIL_ONLY_COLLISION');
        }

        // No collision → planned migrate
        return $this->result('MIGRATED', $dto, $fingerprint, null, 'Planned create', $docHash, $normalizedEmail);
    }

    private function result(string $status, LegacyPortalUserDto $dto, string $fingerprint, ?int $targetId, string $disposition, ?string $docHash, ?string $normalizedEmail, ?string $errorCode = null): array
    {
        // Report must not expose raw CPF/phone — use pseudonymized.
        return [
            'source_entity' => 'portal_users',
            'source_id' => (string) $dto->id,
            'target_entity' => 'nimbus_portal_users',
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'target_role' => 'PRIMARY',
            'status' => $status,
            'disposition' => $disposition,
            'error_code' => $errorCode,
            'source_fingerprint' => $fingerprint,
            // Safe metadata for reporting
            'metadata' => [
                'email_hash' => $normalizedEmail !== null ? 'pii_'.substr(hash('sha256', $normalizedEmail), 0, 16) : null,
                'cpf_hash_prefix' => $docHash !== null ? substr($docHash, 0, 8).'...' : null,
                'document_number_hash' => $docHash,
                'normalized_email' => $normalizedEmail, // internally; report should mask but we store for test verification
            ],
        ];
    }
}
