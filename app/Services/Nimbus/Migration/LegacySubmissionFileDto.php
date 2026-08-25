<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacySubmissionFileDto
{
    public function __construct(
        public int|string $id,
        public int|string $submissionId,
        public string $documentType,
        public string $origin,
        public bool $visibleToUser,
        public string $originalName,
        public string $storedName,
        public ?string $mimeType,
        public int $sizeBytes,
        public string $storagePath,
        public ?string $checksum,
        public int $currentVersion,
        public string $uploadedAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            submissionId: $row['submission_id'],
            documentType: $row['document_type'] ?? 'OTHER',
            origin: $row['origin'] ?? 'USER',
            visibleToUser: (bool) ($row['visible_to_user'] ?? false),
            originalName: $row['original_name'],
            storedName: $row['stored_name'],
            mimeType: $row['mime_type'] ?? null,
            sizeBytes: (int) ($row['size_bytes'] ?? 0),
            storagePath: $row['storage_path'],
            checksum: $row['checksum'] ?? null,
            currentVersion: (int) ($row['current_version'] ?? 1),
            uploadedAt: $row['uploaded_at'] ?? now()->toDateTimeString(),
        );
    }
}
