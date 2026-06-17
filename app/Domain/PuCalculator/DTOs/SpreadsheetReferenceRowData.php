<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class SpreadsheetReferenceRowData
{
    public function __construct(
        public CarbonImmutable $date,
        public ?string $unitBaseValue,
        public ?string $correctedUnitValue,
        public ?string $factorDi,
        public ?string $factorDiAccumulated,
        public ?string $factorSpread,
        public ?string $factorSpreadDi,
        public ?string $updatedUnitValue,
        public ?string $residualUnitValue,
        public ?string $interestRealUnitValue,
        public ?string $amortizationUnitValue,
        public ?string $quantity,
        public ?string $totalValue,
        public ?string $paymentInterestTotal,
        public ?string $paymentAmortizationPrincipalTotal,
        public ?string $paymentAmortizationCorrectionTotal,
        public ?string $paymentTotalValue,
        public ?CarbonImmutable $eventOriginalDate,
        public ?CarbonImmutable $eventDueDate,
        public ?CarbonImmutable $indexRateDate,
        public ?string $indexRateValue,
        public ?int $dupCorrection,
        public ?int $dutCorrection,
        public ?int $dupInterest,
        public ?int $dutInterest,
    ) {}

    public function hasPayment(): bool
    {
        return $this->paymentTotalValue !== null
            && bccomp($this->paymentTotalValue, '0', 6) === 1;
    }

    public function hasInterestPayment(): bool
    {
        return $this->paymentInterestTotal !== null
            && bccomp($this->paymentInterestTotal, '0', 6) === 1;
    }

    public function hasAmortization(): bool
    {
        return $this->amortizationUnitValue !== null
            && bccomp($this->amortizationUnitValue, '0', 6) === 1;
    }
}
