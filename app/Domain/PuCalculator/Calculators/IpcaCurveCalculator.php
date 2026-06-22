<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Contracts\PuIndexCalculatorInterface;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException;
use App\Models\Emission;

/**
 * Esqueleto preparatório para IPCA. A engine IPCA ainda não foi homologada (sem gabarito
 * de validação), portanto a geração permanece bloqueada nesta fase.
 */
class IpcaCurveCalculator implements PuIndexCalculatorInterface
{
    public function calculate(Emission $emission): PuCurveGenerationResult
    {
        throw new IndexerNotSupportedException(
            'A curva IPCA ainda está em preparação e não pode ser gerada nesta versão da calculadora.',
        );
    }
}
