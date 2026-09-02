<?php

namespace App\DTOs\Measurements;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class MeasurementCycleReportResult
{
    /**
     * @param  list<MeasurementStageMetrics>  $stageMetrics
     * @param  list<MeasurementCycleReportRow>  $rows
     * @param  list<MeasurementCurrentWorkload>  $workload
     * @param  array<int, string>  $operationOptions
     * @param  array<int, string>  $emissionOptions
     * @param  array<int, string>  $measurementOptions
     * @param  array<int, string>  $actorOptions
     * @param  array<int, string>  $responsibleOptions
     */
    public function __construct(
        public MeasurementCycleReportSummary $summary,
        public MeasurementHistoricalCoverage $coverage,
        public array $stageMetrics,
        public array $rows,
        public int $totalRows,
        public int $currentPage,
        public int $perPage,
        public array $workload,
        public array $operationOptions,
        public array $emissionOptions,
        public array $measurementOptions,
        public array $actorOptions,
        public array $responsibleOptions,
    ) {}

    /** @param array<string, mixed> $query */
    public function paginator(string $path, array $query = []): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->rows,
            $this->totalRows,
            $this->perPage,
            $this->currentPage,
            ['path' => $path, 'query' => $query],
        );
    }
}
