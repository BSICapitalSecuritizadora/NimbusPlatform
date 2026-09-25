<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;

/**
 * Quais empreendimentos estão sob automação, e desde quando.
 *
 * É a costura entre o motor da automação e o rollout por Emissão. Toda a
 * automação -- descoberta, retry, encerramento de alvos órfãos, recorte dos
 * lembretes -- pergunta a esta interface e a mais nada. Em produção a resposta é
 * a de {@see DatabaseSalesBoardAutomationEligibilityProvider}: modo
 * automatizado, competência inicial e escopo homologado íntegro.
 */
interface SalesBoardAutomationEligibilityProvider
{
    /**
     * Os empreendimentos habilitados, com a competência em que a automação
     * passou a valer para cada um.
     *
     * @return list<SalesBoardAutomationEligibleTarget>
     */
    public function eligibleTargets(): array;
}
