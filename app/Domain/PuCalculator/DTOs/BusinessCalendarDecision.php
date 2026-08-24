<?php

namespace App\Domain\PuCalculator\DTOs;

final readonly class BusinessCalendarDecision
{
    /**
     * @param  array<string, mixed>|null  $override
     */
    public function __construct(
        public bool $isBusinessDay,
        public string $reason,
        public string $calendarCode,
        public string $calendarLabel,
        public ?string $source,
        public int $revision,
        public ?string $document,
        public ?string $sourceRevision,
        public ?array $override,
        public bool $inferredWeekend,
        public string $coverageState,
        public ?string $yearStatus,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_business_day' => $this->isBusinessDay,
            'reason' => $this->reason,
            'calendar_code' => $this->calendarCode,
            'calendar_label' => $this->calendarLabel,
            'source' => $this->source,
            'revision' => $this->revision,
            'document' => $this->document,
            'source_revision' => $this->sourceRevision,
            'override' => $this->override,
            'inferred_weekend' => $this->inferredWeekend,
            'coverage_state' => $this->coverageState,
            'year_status' => $this->yearStatus,
        ];
    }
}
