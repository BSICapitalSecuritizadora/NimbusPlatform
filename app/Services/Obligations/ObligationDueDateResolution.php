<?php

namespace App\Services\Obligations;

use App\Enums\ObligationDueDateCalculationStatus;
use Carbon\CarbonImmutable;

final readonly class ObligationDueDateResolution
{
    /**
     * @param  list<array{date:string,reason:string}>  $skippedDates
     * @param  list<array<string, mixed>>  $calendarYears
     */
    public function __construct(
        public ?CarbonImmutable $dueDate,
        public string $rule,
        public ?CarbonImmutable $anchorDate = null,
        public ?string $calendarCode = null,
        public ?int $quantity = null,
        public ?string $direction = null,
        public ?string $initialDateInclusion = null,
        public array $skippedDates = [],
        public ?ObligationDueDateCalculationStatus $calculationStatus = null,
        public ?CarbonImmutable $calculationPeriodFrom = null,
        public ?CarbonImmutable $calculationPeriodTo = null,
        public array $calendarYears = [],
        public ?string $blockingReason = null,
        public ?string $requiredCalendarDate = null,
        public ?CarbonImmutable $calculatedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'due_date' => $this->dueDate?->toDateString(),
            'rule' => $this->rule,
            'anchor_date' => $this->anchorDate?->toDateString(),
            'calendar_code' => $this->calendarCode,
            'quantity' => $this->quantity,
            'direction' => $this->direction,
            'initial_date_inclusion' => $this->initialDateInclusion,
            'skipped_dates' => $this->skippedDates,
            'calculation_status' => ($this->calculationStatus
                ?? ($this->dueDate === null ? null : ObligationDueDateCalculationStatus::Calculated))?->value,
            'calculation_period' => [
                'from' => $this->calculationPeriodFrom?->toDateString(),
                'to' => $this->calculationPeriodTo?->toDateString(),
            ],
            'calendar_years' => $this->calendarYears,
            'blocking_reason' => $this->blockingReason,
            'required_calendar_date' => $this->requiredCalendarDate,
            'calculated_at' => $this->dueDate === null
                ? null
                : ($this->calculatedAt ?? CarbonImmutable::now())->toIso8601String(),
        ];
    }
}
