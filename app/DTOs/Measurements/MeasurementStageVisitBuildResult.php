<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementHistoryCompleteness;

final readonly class MeasurementStageVisitBuildResult
{
    /**
     * @param  list<MeasurementStageVisit>  $visits
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $visits,
        public MeasurementHistoryCompleteness $completeness,
        public array $warnings,
    ) {}
}
