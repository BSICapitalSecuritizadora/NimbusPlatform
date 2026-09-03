<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuExternalBenchmarkDataset
{
    /** @param list<PuExternalBenchmarkRowData> $rows */
    public function __construct(
        public string $inputFileName,
        public string $fileSha256,
        public string $datasetSha256,
        public array $rows,
        public string $fromDate,
        public string $toDate,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'input_file_name' => $this->inputFileName,
            'file_sha256' => $this->fileSha256,
            'dataset_sha256' => $this->datasetSha256,
            'row_count' => count($this->rows),
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
        ];
    }
}
