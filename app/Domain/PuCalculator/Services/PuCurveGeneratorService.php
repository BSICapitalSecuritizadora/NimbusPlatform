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

    public function handle(Emission $emission): PuCurveGenerationResult
    {
        return $this->calculatorFactory->for($emission)->calculate($emission);
    }
}
