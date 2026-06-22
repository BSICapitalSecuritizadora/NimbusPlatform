<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuIndexCoverageReport
{
    /**
     * @param  list<string>  $missingCalendarDates
     * @param  list<string>  $missingIndexDates
     * @param  list<string>  $projectedIndexDates
     */
    public function __construct(
        public bool $hasParameter,
        public ?string $indexer,
        public ?string $startDate,
        public ?string $endDate,
        public array $missingCalendarDates,
        public array $missingIndexDates,
        public array $projectedIndexDates,
        public ?string $lastAvailableIndexDate,
    ) {}

    public function hasBlockingGaps(): bool
    {
        return $this->missingCalendarDates !== [] || $this->missingIndexDates !== [];
    }

    public function usesProjectedIndex(): bool
    {
        return $this->projectedIndexDates !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'has_parameter' => $this->hasParameter,
            'indexer' => $this->indexer,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'missing_calendar_dates' => $this->missingCalendarDates,
            'missing_index_dates' => $this->missingIndexDates,
            'projected_index_dates' => $this->projectedIndexDates,
            'last_available_index_date' => $this->lastAvailableIndexDate,
            'has_blocking_gaps' => $this->hasBlockingGaps(),
        ];
    }
}
