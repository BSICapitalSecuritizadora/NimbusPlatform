<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkRowData;

final class PuExternalBenchmarkFingerprintService
{
    /** @param list<PuExternalBenchmarkRowData> $rows */
    public function dataset(array $rows): string
    {
        $payload = array_map(
            fn (PuExternalBenchmarkRowData $row): array => $row->toArray(),
            $rows,
        );
        usort($payload, fn (array $left, array $right): int => $left['reference_date'] <=> $right['reference_date']);

        return $this->hash([
            'schema' => 'pu-external-benchmark-v1',
            'rows' => $payload,
        ]);
    }

    public function importIdentity(
        int $emissionId,
        string $sourceType,
        string $sourceName,
        ?int $sourceEvidenceId,
        ?int $sourceDocumentId,
        string $referenceAsOf,
        string $datasetSha256,
    ): string {
        return $this->hash([
            'schema' => 'pu-external-benchmark-import-v1',
            'emission_id' => $emissionId,
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'source_evidence_id' => $sourceEvidenceId,
            'source_document_id' => $sourceDocumentId,
            'reference_as_of' => $referenceAsOf,
            'dataset_sha256' => $datasetSha256,
        ]);
    }

    /**
     * @param  list<array<string, ?string>>  $rows
     * @param  list<array{reference_date:string,gap_type:string}>  $gaps
     */
    public function comparison(
        string $candidateChecksum,
        string $benchmarkDatasetSha256,
        string $algorithmVersion,
        string $coverageStatus,
        array $rows,
        array $gaps,
    ): string {
        usort($rows, fn (array $left, array $right): int => (string) $left['reference_date'] <=> (string) $right['reference_date']);
        usort($gaps, fn (array $left, array $right): int => sprintf(
            '%s|%s',
            $left['reference_date'],
            $left['gap_type'],
        ) <=> sprintf(
            '%s|%s',
            $right['reference_date'],
            $right['gap_type'],
        ));

        return $this->hash([
            'schema' => 'pu-external-comparison-v1',
            'candidate_checksum' => $candidateChecksum,
            'benchmark_dataset_sha256' => $benchmarkDatasetSha256,
            'algorithm_version' => $algorithmVersion,
            'coverage_status' => $coverageStatus,
            'rows' => $rows,
            'gaps' => $gaps,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
