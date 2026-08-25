<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class FirstCouponPreIntegralizationPremiumData
{
    /**
     * @param  list<array<string, mixed>>  $accrualDays
     * @param  array<string, mixed>|null  $evidence
     */
    public function __construct(
        public int $businessDays,
        public string $calendarCode,
        public bool $appliesIndexFactor,
        public bool $appliesSpreadFactor,
        public array $accrualDays,
        public string $factorDi,
        public string $factorSpread,
        public string $factor,
        public ?array $evidence,
    ) {}

    /** @return array<string, mixed> */
    public function toCalculationMemory(): array
    {
        return [
            'name' => 'Prêmio pré-integralização do primeiro cupom',
            'application' => 'first_interest_payment_only',
            'business_days_before_start' => $this->businessDays,
            'calendar_code' => $this->calendarCode,
            'apply_index_factor' => $this->appliesIndexFactor,
            'apply_spread_factor' => $this->appliesSpreadFactor,
            'accrual_days' => $this->accrualDays,
            'factor_di' => $this->factorDi,
            'factor_spread' => $this->factorSpread,
            'factor' => $this->factor,
            'precision' => [
                'daily_di_factor' => 8,
                'accumulated_di_factor' => 8,
                'spread_factor' => 9,
                'combined_interest_factor' => 9,
            ],
            'evidence' => $this->evidence,
        ];
    }
}
