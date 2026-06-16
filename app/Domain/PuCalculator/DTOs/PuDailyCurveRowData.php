<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class PuDailyCurveRowData
{
    public function __construct(
        public CarbonImmutable $date,
        public bool $isBusinessDay,
        public string $unitBaseValue,
        public string $unitCorrectedValue,
        public string $factorDi,
        public string $factorDiAccumulated,
        public string $factorSpread,
        public string $factorSpreadDi,
        public string $interestRealUnitValue,
        public string $updatedUnitValue,
        public string $amortizationRatio,
        public string $amortizationUnitValue,
        public string $residualUnitValue,
        public string $quantity,
        public string $totalValue,
        public string $interestPaymentUnitValue,
        public string $interestPaymentValue,
        public string $paymentTotalUnitValue,
        public string $paymentTotalValue,
        public ?int $dupCorrection,
        public ?int $dutCorrection,
        public ?int $dupInterest,
        public ?int $dutInterest,
        public ?CarbonImmutable $indexRateDate,
        public ?string $indexRateValue,
        public ?CarbonImmutable $eventOriginalDate,
        public ?CarbonImmutable $eventEffectiveDate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPersistenceArray(int $emissionId): array
    {
        return [
            'emission_id' => $emissionId,
            'curve_date' => $this->date->toDateString(),
            'is_business_day' => $this->isBusinessDay,
            'unit_base_value' => $this->unitBaseValue,
            'unit_corrected_value' => $this->unitCorrectedValue,
            'factor_di' => $this->factorDi,
            'factor_di_accumulated' => $this->factorDiAccumulated,
            'factor_spread' => $this->factorSpread,
            'factor_spread_di' => $this->factorSpreadDi,
            'interest_real_unit_value' => $this->interestRealUnitValue,
            'updated_unit_value' => $this->updatedUnitValue,
            'amortization_ratio' => $this->amortizationRatio,
            'amortization_unit_value' => $this->amortizationUnitValue,
            'residual_unit_value' => $this->residualUnitValue,
            'quantity' => $this->quantity,
            'total_value' => $this->totalValue,
            'interest_payment_unit_value' => $this->interestPaymentUnitValue,
            'interest_payment_value' => $this->interestPaymentValue,
            'payment_total_unit_value' => $this->paymentTotalUnitValue,
            'payment_total_value' => $this->paymentTotalValue,
            'dup_correction' => $this->dupCorrection,
            'dut_correction' => $this->dutCorrection,
            'dup_interest' => $this->dupInterest,
            'dut_interest' => $this->dutInterest,
            'index_rate_date' => $this->indexRateDate?->toDateString(),
            'index_rate_value' => $this->indexRateValue,
            'event_original_date' => $this->eventOriginalDate?->toDateString(),
            'event_effective_date' => $this->eventEffectiveDate?->toDateString(),
        ];
    }

    public function hasPayment(): bool
    {
        return bccomp($this->paymentTotalValue, '0', 12) === 1;
    }
}
