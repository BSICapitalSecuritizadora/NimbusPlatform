<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCurveGenerationResult
{
    /**
     * @param  list<PuDailyCurveRowData>  $rows
     */
    public function __construct(
        public array $rows,
    ) {}

    /**
     * @return list<PuDailyCurveRowData>
     */
    public function paymentRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (PuDailyCurveRowData $row): bool => $row->hasPayment(),
        ));
    }
}
