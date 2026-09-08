<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Models\Emission;

interface PuIndexCalculatorInterface
{
    /**
     * Gera a curva diária de PU para a emissão conforme o indexador do calculator.
     *
     * `$indexRateCalendarCode` é o calendário de observação do índice, usado
     * somente para localizar a data da taxa divulgada. Nulo (o padrão, e todo o
     * caminho de produção) significa "o mesmo calendário contratual da curva".
     */
    public function calculate(Emission $emission, ?string $indexRateCalendarCode = null): PuCurveGenerationResult;
}
