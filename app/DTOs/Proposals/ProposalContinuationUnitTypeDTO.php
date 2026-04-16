<?php

declare(strict_types=1);

namespace App\DTOs\Proposals;

use App\Concerns\MoneyFormatter;

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
            usableArea: MoneyFormatter::normalizeDecimalValue($data['usable_area'] ?? 0),
            averagePrice: MoneyFormatter::normalizeDecimalValue($data['average_price'] ?? 0),
        );
    }

    protected static function integer(mixed $value): int
    {
        return (int) round(MoneyFormatter::normalizeDecimalValue($value));
    }
}
