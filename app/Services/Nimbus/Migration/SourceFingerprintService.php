<?php

namespace App\Services\Nimbus\Migration;

/**
 * Deterministic SHA-256 fingerprint for a source record.
 * Canonical JSON: sorted keys, trimmed strings, no migration-time values.
 */
class SourceFingerprintService
{
    public static function fingerprint(array $normalizedData): string
    {
        $canonical = self::canonicalize($normalizedData);
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return hash('sha256', $json);
    }

    public static function forPortalUser(LegacyPortalUserDto $dto): string
    {
        return self::fingerprint([
            'id' => (string) $dto->id,
            'full_name' => trim($dto->fullName),
            'email' => $dto->email !== null ? strtolower(trim($dto->email)) : null,
            'document_number' => $dto->documentNumber !== null ? preg_replace('/\D+/', '', $dto->documentNumber) : null,
            'phone_number' => $dto->phoneNumber !== null ? preg_replace('/\D+/', '', $dto->phoneNumber) : null,
            'external_id' => $dto->externalId !== null ? trim($dto->externalId) : null,
            'status' => $dto->status,
            'created_at' => $dto->createdAt,
        ]);
    }

    public static function forSubmission(LegacySubmissionDto $dto): string
    {
        return self::fingerprint([
            'id' => (string) $dto->id,
            'portal_user_id' => (string) $dto->portalUserId,
            'reference_code' => trim($dto->referenceCode),
            'title' => trim($dto->title),
            'status' => $dto->status,
            'submitted_at' => $dto->submittedAt,
            'company_cnpj' => $dto->companyCnpj,
            'created_at' => $dto->createdAt,
        ]);
    }

    public static function forFile(LegacySubmissionFileDto $dto): string
    {
        return self::fingerprint([
            'id' => (string) $dto->id,
            'submission_id' => (string) $dto->submissionId,
            'document_type' => $dto->documentType,
            'origin' => $dto->origin,
            'original_name' => $dto->originalName,
            'stored_name' => $dto->storedName,
            'storage_path' => $dto->storagePath,
            'size_bytes' => $dto->sizeBytes,
            'checksum' => $dto->checksum,
            'uploaded_at' => $dto->uploadedAt,
        ]);
    }

    public static function forNote(LegacyNoteDto $dto): string
    {
        return self::fingerprint([
            'id' => (string) $dto->id,
            'submission_id' => (string) $dto->submissionId,
            'visibility' => $dto->visibility,
            'message' => $dto->message,
            'created_at' => $dto->createdAt,
        ]);
    }

    private static function canonicalize(array $data): array
    {
        ksort($data);

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::canonicalize($v);
            }
        }

        return $data;
    }
}
