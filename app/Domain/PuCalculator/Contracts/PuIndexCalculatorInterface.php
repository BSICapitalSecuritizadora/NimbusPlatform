<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
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
     *
     * `$profile` é o perfil de cálculo. O default é `Contractual` -- a regra documental --, e é o
     * único perfil que qualquer caminho operacional pode usar. `LegacyCompatibility` existe apenas
     * para reconciliação na simulação e só é suportado pela engine CDI; os demais calculators
     * recusam explicitamente em vez de ignorar o pedido em silêncio.
     */
    public function calculate(
        Emission $emission,
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): PuCurveGenerationResult;
}
