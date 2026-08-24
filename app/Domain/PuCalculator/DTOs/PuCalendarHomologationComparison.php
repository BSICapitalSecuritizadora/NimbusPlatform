<?php

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCalendarHomologationComparison
{
    /**
     * @param  list<array<string, mixed>>  $dailyDiff
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>|null  $firstDivergence
     * @param  array<int, array<string, mixed>>  $calendarGovernance
     */
    public function __construct(
        public array $dailyDiff,
        public array $summary,
        public ?array $firstDivergence,
        public array $calendarGovernance,
        public string $checksum,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'daily_diff' => $this->dailyDiff,
            'summary' => $this->summary,
            'first_divergence' => $this->firstDivergence,
            'calendar_governance' => $this->calendarGovernance,
            'checksum' => $this->checksum,
        ];
    }
}
