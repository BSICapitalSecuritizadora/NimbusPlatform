<?php

namespace App\DTOs\Measurements;

final readonly class MeasurementStageMetrics
{
    public function __construct(
        public int $stage,
        public int $decisions,
        public int $approvals,
        public int $rejections,
        public int $finalizations,
        public int $finalizationReturns,
        public ?float $rejectionRate,
        public ?float $finalizationReturnRate,
        public ?float $averageCalendarDuration,
        public ?float $medianCalendarDuration,
        public ?float $averageActiveDuration,
        public ?float $medianActiveDuration,
        public int $pausedDurationTotal,
        public ?float $averagePausedDuration,
        public int $completeCohort,
        public int $partialExcluded,
        public int $insufficientExcluded,
    ) {}
}
