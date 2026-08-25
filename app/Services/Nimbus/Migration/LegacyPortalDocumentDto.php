<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyPortalDocumentDto
{
    public function __construct(
        public int|string $id,
        public int|string $portalUserId,
        public string $title,
        public ?string $description,
        public string $filePath,
        public string $fileOriginalName,
        public int $fileSize,
        public string $fileMime,
        public string $createdAt,
        public int|string $createdByAdmin,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            portalUserId: $row['portal_user_id'],
            title: $row['title'],
            description: $row['description'] ?? null,
            filePath: $row['file_path'],
            fileOriginalName: $row['file_original_name'],
            fileSize: (int) ($row['file_size'] ?? 0),
            fileMime: $row['file_mime'] ?? 'application/octet-stream',
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
            createdByAdmin: $row['created_by_admin'] ?? $row['created_by_user_id'] ?? 0,
        );
    }
}
