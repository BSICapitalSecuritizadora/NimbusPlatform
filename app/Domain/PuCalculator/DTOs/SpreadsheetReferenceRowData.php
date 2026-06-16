<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class SpreadsheetReferenceRowData
{
    public function __construct(
        public CarbonImmutable $date,
        public ?string $updatedUnitValue,
        public ?string $residualUnitValue,
        public ?string $interestRealUnitValue,
        public ?string $amortizationUnitValue,
        public ?string $quantity,
        public ?string $totalValue,
        public ?string $paymentTotalValue,
        public ?string $indexRateValue,
        public ?int $dupInterest,
        public ?int $dutInterest,
    ) {}
}
