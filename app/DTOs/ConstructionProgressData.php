<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonInterface;

readonly class ConstructionProgressData extends BaseDTO
{
    /**
     * @param  bool  $measuredInMonth  houve medição vigente na competência; sem ela o realizado do mês é "não medido", não 0%
     */
    public function __construct(
        public ?string $planName,
        public float $plannedMonthlyPercent,
        public float $plannedCumulativePercent,
        public float $realizedMonthlyPercent,
        public float $realizedCumulativePercent,
        public float $diffPercent,
        public ?string $trend,
        public ?CarbonInterface $measurementDate,
        public bool $measuredInMonth = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_name' => $this->planName,
            'planned_monthly_percent' => $this->plannedMonthlyPercent,
            'planned_cumulative_percent' => $this->plannedCumulativePercent,
            'realized_monthly_percent' => $this->realizedMonthlyPercent,
            'realized_cumulative_percent' => $this->realizedCumulativePercent,
            'diff_percent' => $this->diffPercent,
            'trend' => $this->trend,
            'measurement_date' => $this->measurementDate?->toDateString(),
            'measured_in_month' => $this->measuredInMonth,
        ];
    }
}
