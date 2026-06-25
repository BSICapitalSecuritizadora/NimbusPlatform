<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;

/**
 * Centraliza a decisão de POLÍTICA de projeção do IPCA. Mantida separada do resolver para que a regra
 * de negócio ("este parâmetro permite usar índice projetado?") fique explícita e testável de forma
 * isolada, sem inventar projeção silenciosa.
 */
class IpcaProjectionPolicyService
{
    public function resolvePolicy(?string $configuredPolicy): IpcaProjectionPolicy
    {
        return IpcaProjectionPolicy::fromParameter($configuredPolicy);
    }

    public function allowsProjection(?string $configuredPolicy): bool
    {
        return $this->resolvePolicy($configuredPolicy)->allowsProjection();
    }
}
