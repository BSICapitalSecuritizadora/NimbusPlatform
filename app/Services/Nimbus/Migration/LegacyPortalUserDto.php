<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyPortalUserDto
{
    public function __construct(
        public int|string $id,
        public string $fullName,
        public ?string $email,
        public ?string $documentNumber,
        public ?string $phoneNumber,
        public ?string $externalId,
        public ?string $notes,
        public string $status,
        public ?string $lastLoginAt,
        public ?string $lastLoginMethod,
        public string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            fullName: $row['full_name'] ?? $row['fullName'] ?? '',
            email: $row['email'] ?? null,
            documentNumber: $row['document_number'] ?? $row['documentNumber'] ?? null,
            phoneNumber: $row['phone_number'] ?? $row['phoneNumber'] ?? null,
            externalId: $row['external_id'] ?? null,
            notes: $row['notes'] ?? null,
            status: $row['status'] ?? 'INVITED',
            lastLoginAt: $row['last_login_at'] ?? null,
            lastLoginMethod: $row['last_login_method'] ?? null,
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
            updatedAt: $row['updated_at'] ?? null,
        );
    }
}
