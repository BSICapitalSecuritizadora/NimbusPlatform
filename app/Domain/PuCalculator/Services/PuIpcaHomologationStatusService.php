<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexProjectionSeries;

/**
 * Status de homologação OPERACIONAL e CONTEXTUAL do IPCA por emissão.
 *
 * Decisão de governança: o flag estático `PuIndexer::Ipca::isHomologated()` permanece `false`
 * (a engine, como um todo, não é declarada homologada por default). A homologação operacional é
 * sempre contextual a uma emissão e só é verdadeira quando:
 *   1. existe uma VERSÃO de curva HOMOLOGADA (maker/checker) para a emissão; e
 *   2. sob política de mercado, existe uma SÉRIE PROJETADA APROVADA cobrindo a projeção.
 *
 * Nunca vira `true` automaticamente: depende de homologação maker/checker bem-sucedida.
 */
class PuIpcaHomologationStatusService
{
    public function isOperationallyHomologated(Emission $emission): bool
    {
        $emission->loadMissing('puParameter');
        $parameter = $emission->puParameter;

        if ($parameter === null || $parameter->indexer_enum !== PuIndexer::Ipca) {
            return false;
        }

        $hasHomologatedVersion = EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->homologated()
            ->exists();

        if (! $hasHomologatedVersion) {
            return false;
        }

        $policy = IpcaProjectionPolicy::fromParameter($parameter->index_projection_policy);

        if (! $policy->allowsProjection()) {
            return true;
        }

        return IndexProjectionSeries::query()
            ->forIndexer(PuIndexer::Ipca)
            ->approved()
            ->exists();
    }
}
