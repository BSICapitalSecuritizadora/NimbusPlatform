<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuValidationStatus;

final readonly class PuValidationReport
{
    /**
     * @param  list<PuValidationRowResult>  $rows
     */
    public function __construct(
        public string $sheetName,
        public int $totalRowsCompared,
        public int $totalDivergences,
        public string $largestPuDifference,
        public string $largestTotalValueDifference,
        public string $largestPaymentDifference,
        public PuValidationStatus $status,
        public array $rows,
    ) {}
}
