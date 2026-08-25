<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyNoteDto
{
    public function __construct(
        public int|string $id,
        public int|string $submissionId,
        public ?string $adminUserId,
        public string $visibility,
        public string $message,
        public string $createdAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            submissionId: $row['submission_id'],
            adminUserId: isset($row['admin_user_id']) ? (string) $row['admin_user_id'] : null,
            visibility: $row['visibility'] ?? 'USER_VISIBLE',
            message: $row['message'],
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
        );
    }
}
