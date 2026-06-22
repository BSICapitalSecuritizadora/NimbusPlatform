<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Contracts\PuIndexCalculatorInterface;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Services\PuCurveGenerationService;
use App\Models\Emission;

/**
 * Adapta a engine CDI + spread já homologada (PuCurveGenerationService) ao contrato
 * multi-indexador. Não contém lógica financeira: apenas delega, preservando o CDI calibrado.
 */
class CdiSpreadCurveCalculator implements PuIndexCalculatorInterface
{
    public function __construct(
        private readonly PuCurveGenerationService $cdiGenerationService,
    ) {}

    public function calculate(Emission $emission): PuCurveGenerationResult
    {
        return $this->cdiGenerationService->handle($emission);
    }
}
