<?php

namespace App\DTOs\Proposals;

use App\Models\ProposalProject;

readonly class ProposalContinuationUnitTypeDTO
{
    public function __construct(
        public int $totalUnits,
        public string $bedrooms,
        public string $parkingSpaces,
        public float $usableArea,
        public float $averagePrice,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            totalUnits: self::integer($data['total_units'] ?? 0),
            bedrooms: trim((string) ($data['bedrooms'] ?? '')),
            parkingSpaces: trim((string) ($data['parking_spaces'] ?? '')),
            usableArea: ProposalProject::normalizeDecimalValue($data['usable_area'] ?? 0),
            averagePrice: ProposalProject::normalizeDecimalValue($data['average_price'] ?? 0),
        );
    }

    protected static function integer(mixed $value): int
    {
        return (int) round(ProposalProject::normalizeDecimalValue($value));
    }
}
