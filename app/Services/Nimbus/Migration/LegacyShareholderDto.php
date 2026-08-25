<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyShareholderDto
{
    public function __construct(
        public int|string $id,
        public int|string $submissionId,
        public string $name,
        public ?string $documentRg,
        public ?string $documentCnpj,
        public string $percentage,
        public string $createdAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            submissionId: $row['submission_id'],
            name: $row['name'],
            documentRg: $row['document_rg'] ?? null,
            documentCnpj: $row['document_cnpj'] ?? null,
            percentage: (string) ($row['percentage'] ?? '0'),
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
        );
    }
}
