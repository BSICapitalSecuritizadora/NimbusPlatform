<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Models\Emission;

interface PuIndexCalculatorInterface
{
    /**
     * Gera a curva diária de PU para a emissão conforme o indexador do calculator.
     */
    public function calculate(Emission $emission): PuCurveGenerationResult;
}
