<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use Carbon\CarbonImmutable;

final readonly class PuValidationReport
{
    /**
     * @param  list<PuValidationRowResult>  $rows
     * @param  array<string, PuValidationFieldDifference>  $largestDifferencesByField
     * @param  array<string, int>  $divergenceCountByField
     * @param  array<string, int>  $divergenceCountByCause
     */
    public function __construct(
        public string $sheetName,
        public int $totalRowsCompared,
        public int $totalDivergences,
        public int $totalFieldDivergences,
        public string $largestPuDifference,
        public string $largestTotalValueDifference,
        public string $largestPaymentDifference,
        public PuValidationStatus $status,
        public array $rows,
        public ?CarbonImmutable $firstDivergenceDate = null,
        public array $largestDifferencesByField = [],
        public array $divergenceCountByField = [],
        public array $divergenceCountByCause = [],
        public ?string $calculationVersion = null,
        public PuValidationMode $mode = PuValidationMode::RawScale,
        public ?CarbonImmutable $rangeStart = null,
        public ?CarbonImmutable $rangeEnd = null,
    ) {}

    /**
     * @return list<PuValidationRowResult>
     */
    public function divergentRows(int $limit = 10): array
    {
        return array_slice(
            array_values(array_filter(
                $this->rows,
                static fn (PuValidationRowResult $row): bool => ! $row->approved,
            )),
            0,
            $limit,
        );
    }

    /**
     * @return list<PuValidationFieldDifference>
     */
    public function topFieldDifferences(int $limit = 10): array
    {
        return array_slice(array_values($this->largestDifferencesByField), 0, $limit);
    }
}
