<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Factories\PuCalculatorFactory;
use App\Models\Emission;

class PuCurveGeneratorService
{
    public function __construct(
        private readonly PuCalculatorFactory $calculatorFactory,
    ) {}

    /**
     * `$indexRateCalendarCode` é a hipótese de calendário de OBSERVAÇÃO do
     * índice. Nulo em toda a produção: o calculator então resolve as datas de
     * taxa pelo próprio calendário contratual da curva, como sempre.
     */
    public function handle(Emission $emission, ?string $indexRateCalendarCode = null): PuCurveGenerationResult
    {
        return $this->calculatorFactory->for($emission)->calculate($emission, $indexRateCalendarCode);
    }
}
