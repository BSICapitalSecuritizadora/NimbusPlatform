<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;

/**
 * Quais empreendimentos estão sob automação, e desde quando.
 *
 * É a única costura entre a Fase F e o rollout que ainda não existe. Toda a
 * automação -- descoberta, retry, alertas, observabilidade -- pergunta a esta
 * interface e a mais nada; quando a Fase G decidir habilitar por Emissão, ela
 * troca a implementação e o resto da fase não muda uma linha.
 *
 * Foi por isso que a habilitação não virou coluna em `emissions` agora: uma
 * coluna nasceria com semântica adivinhada, e migrá-la depois custaria mais do
 * que trocar um binding.
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
