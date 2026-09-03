<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class PuExternalBenchmarkRowData
{
    public function __construct(
        public CarbonImmutable $referenceDate,
        public string $unitValue,
    ) {}

    /** @return array{reference_date:string,unit_value:string} */
    public function toArray(): array
    {
        return [
            'reference_date' => $this->referenceDate->toDateString(),
            'unit_value' => $this->unitValue,
        ];
    }
}
