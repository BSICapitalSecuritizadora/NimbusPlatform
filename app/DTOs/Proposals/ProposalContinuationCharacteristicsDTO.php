<?php

declare(strict_types=1);

namespace App\DTOs\Proposals;

use App\Concerns\MoneyFormatter;

readonly class ProposalContinuationCharacteristicsDTO
{
    public function __construct(
        public int $blocks,
        public int $floors,
        public int $typicalFloors,
        public int $unitsPerFloor,
        public ?int $totalUnits,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            blocks: self::integer($data['blocks'] ?? 0),
            floors: self::integer($data['floors'] ?? 0),
            typicalFloors: self::integer($data['typical_floors'] ?? 0),
            unitsPerFloor: self::integer($data['units_per_floor'] ?? 0),
            totalUnits: self::nullableInteger($data['total_units'] ?? null),
        );
    }

    protected static function integer(mixed $value): int
    {
        return (int) round(MoneyFormatter::normalizeDecimalValue($value));
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return self::integer($value);
    }
}
