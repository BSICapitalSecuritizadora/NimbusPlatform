<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyGeneralDocumentDto
{
    public function __construct(
        public int|string $id,
        public int|string $categoryId,
        public string $title,
        public ?string $description,
        public string $filePath,
        public string $fileMime,
        public int $fileSize,
        public string $fileOriginalName,
        public bool $isActive,
        public string $publishedAt,
        public int|string $createdByAdmin,
        public string $createdAt,
        public ?string $categoryName = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            categoryId: $row['category_id'] ?? $row['nimbus_category_id'] ?? 0,
            title: $row['title'],
            description: $row['description'] ?? null,
            filePath: $row['file_path'],
            fileMime: $row['file_mime'] ?? $row['file_mime_type'] ?? 'application/octet-stream',
            fileSize: (int) ($row['file_size'] ?? 0),
            fileOriginalName: $row['file_original_name'],
            isActive: (bool) ($row['is_active'] ?? true),
            publishedAt: $row['published_at'] ?? now()->toDateTimeString(),
            createdByAdmin: $row['created_by_admin'] ?? $row['created_by_user_id'] ?? 0,
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
            categoryName: $row['category_name'] ?? null,
        );
    }
}
