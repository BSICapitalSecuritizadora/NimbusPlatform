<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkRowData;
use App\Domain\PuCalculator\Enums\PuExternalBenchmarkStatus;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use Carbon\CarbonImmutable;

final class PuExternalBenchmarkIntegrityService
{
    public function __construct(private readonly PuExternalBenchmarkFingerprintService $fingerprints) {}

    /** @return array{valid:bool,reason:string,checksum:?string} */
    public function inspect(EmissionPuExternalBenchmark $benchmark): array
    {
        $rows = $benchmark->rows()
            ->orderBy('reference_date')
            ->orderBy('id')
            ->get(['id', 'reference_date', 'unit_value']);

        if ($benchmark->status !== PuExternalBenchmarkStatus::Ready || $rows->isEmpty()) {
            return $this->failure('The external benchmark is not a ready non-empty dataset.');
        }

        $rowData = $rows
            ->map(fn (EmissionPuExternalBenchmarkRow $row): PuExternalBenchmarkRowData => new PuExternalBenchmarkRowData(
                referenceDate: CarbonImmutable::parse($row->reference_date)->startOfDay(),
                unitValue: (string) $row->unit_value,
            ))
            ->all();
        $checksum = $this->fingerprints->dataset($rowData);
        $fromDate = $rowData[0]->referenceDate->toDateString();
        $toDate = $rowData[array_key_last($rowData)]->referenceDate->toDateString();

        if (count($rowData) !== $benchmark->row_count
            || $benchmark->from_date?->toDateString() !== $fromDate
            || $benchmark->to_date?->toDateString() !== $toDate
            || ! hash_equals($benchmark->dataset_sha256, $checksum)) {
            return $this->failure('The persisted external benchmark metadata or dataset checksum is inconsistent.', $checksum);
        }

        return ['valid' => true, 'reason' => 'The external benchmark dataset is intact.', 'checksum' => $checksum];
    }

    /** @return array{valid:false,reason:string,checksum:?string} */
    private function failure(string $reason, ?string $checksum = null): array
    {
        return ['valid' => false, 'reason' => $reason, 'checksum' => $checksum];
    }
}
