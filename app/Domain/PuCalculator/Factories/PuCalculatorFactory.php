<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Factories;

use App\Domain\PuCalculator\Calculators\CdiSpreadCurveCalculator;
use App\Domain\PuCalculator\Calculators\FixedRateCurveCalculator;
use App\Domain\PuCalculator\Calculators\IpcaCurveCalculator;
use App\Domain\PuCalculator\Contracts\PuIndexCalculatorInterface;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\Emission;

class PuCalculatorFactory
{
    public function __construct(
        private readonly CdiSpreadCurveCalculator $cdiCalculator,
        private readonly FixedRateCurveCalculator $fixedRateCalculator,
        private readonly IpcaCurveCalculator $ipcaCalculator,
    ) {}

    public function for(Emission $emission): PuIndexCalculatorInterface
    {
        return $this->forIndexer($emission->puParameter?->indexer_enum ?? PuIndexer::Cdi);
    }

    public function forIndexer(PuIndexer $indexer): PuIndexCalculatorInterface
    {
        return match ($indexer) {
            PuIndexer::Cdi => $this->cdiCalculator,
            PuIndexer::Prefixed => $this->fixedRateCalculator,
            PuIndexer::Ipca => $this->ipcaCalculator,
        };
    }
}
