<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuIndexCoverageReport
{
    /**
     * @param  list<string>  $missingCalendarDates
     * @param  list<string>  $missingIndexDates
     * @param  list<string>  $projectedIndexDates
     * @param  list<string>  $missingIndexMessages
     * @param  list<string>  $pendingIndexDates
     * @param  list<string>  $pendingIndexMessages
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
        public array $missingIndexMessages = [],
        public array $pendingIndexDates = [],
        public array $pendingIndexMessages = [],
        public ?string $financialRequirementStartDate = null,
    ) {}

    public function hasBlockingGaps(): bool
    {
        return $this->missingCalendarDates !== [] || $this->missingIndexDates !== [];
    }

    public function usesProjectedIndex(): bool
    {
        return $this->projectedIndexDates !== [];
    }

    public function awaitsIndexPublication(): bool
    {
        return $this->pendingIndexDates !== [];
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
            'financial_requirement_start_date' => $this->financialRequirementStartDate,
            'missing_calendar_dates' => $this->missingCalendarDates,
            'missing_index_dates' => $this->missingIndexDates,
            'projected_index_dates' => $this->projectedIndexDates,
            'last_available_index_date' => $this->lastAvailableIndexDate,
            'missing_index_messages' => $this->missingIndexMessages,
            'pending_index_dates' => $this->pendingIndexDates,
            'pending_index_messages' => $this->pendingIndexMessages,
            'awaits_index_publication' => $this->awaitsIndexPublication(),
            'has_blocking_gaps' => $this->hasBlockingGaps(),
        ];
    }
}
