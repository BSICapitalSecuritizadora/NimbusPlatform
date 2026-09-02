<?php

namespace App\DTOs\Measurements;

final readonly class MeasurementCycleReportSummary
{
    public function __construct(
        public int $decisions,
        public int $approvals,
        public int $rejections,
        public ?float $rejectionRate,
        public int $finalizationReturns,
        public int $finalizations,
        public ?float $finalizationReturnRate,
        public int $pausedDurationTotal,
        public ?float $averageCycleDuration,
        public ?float $medianCycleDuration,
        public int $eligibleStageVisits,
        public int $eligibleCycles,
    ) {}
}
