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
     * `$accrualCalendarCode` é a HIPÓTESE de calendário de accrual da curva: decide quais dias contam
     * como Dia Útil para DU, DUP/DUT e fator diário. Nulo devolve o calendário contratual do parâmetro,
     * que é o caminho de toda a produção. Eventos e convenção de pagamento nunca o consultam.
     *
     * `$indexRateCalendarCode` é o calendário de observação do índice, usado
     * somente para localizar a data da taxa divulgada. Nulo (o padrão, e todo o
     * caminho de produção) significa "o mesmo calendário contratual da curva".
     */
    public function calculate(
        Emission $emission,
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
    ): PuCurveGenerationResult;
}
