<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class CdiSourceComparisonResult
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, int>>  $byYear
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $summary,
        public array $byYear,
        public array $rows,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'by_year' => $this->byYear,
            'rows' => $this->rows,
            'checksum' => hash('sha256', (string) json_encode(
                ['summary' => $this->summary, 'by_year' => $this->byYear, 'rows' => $this->rows],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ];
    }
}
