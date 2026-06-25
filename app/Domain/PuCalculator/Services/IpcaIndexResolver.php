<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\IpcaIndexResolution;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException;
use Carbon\CarbonImmutable;

/**
 * Resolve o número-índice IPCA de um mês de referência aplicando a política de projeção.
 *
 * Contrato:
 *  - Número-índice PUBLICADO existe → usa-o (type = published), independentemente da política.
 *  - Número-índice PROJETADO existe e a política permite (Market) → usa-o (type = projected), com fonte.
 *  - Número-índice PROJETADO existe mas a política NÃO permite → lança exceção clara (projeção não aprovada).
 *  - Não existe número-índice algum → lança exceção clara nomeando o mês e a política exigida.
 *
 * Jamais inventa projeção silenciosa nem mascara índice projetado como publicado.
 */
class IpcaIndexResolver
{
    public function __construct(
        private readonly IndexRateProvider $indexRateProvider,
        private readonly IpcaProjectionPolicyService $projectionPolicyService,
    ) {}

    public function resolve(
        CarbonImmutable $referenceMonth,
        ?string $configuredPolicy,
        CarbonImmutable $contextDate,
    ): IpcaIndexResolution {
        $policy = $this->projectionPolicyService->resolvePolicy($configuredPolicy);
        $rate = $this->indexRateProvider->exactRateForDate(PuIndexer::Ipca, $referenceMonth);

        if ($rate === null) {
            throw new IndexerNotSupportedException(sprintf(
                'Não há número-índice IPCA cadastrado para %s (curva em %s). %s',
                $referenceMonth->toDateString(),
                $contextDate->toDateString(),
                $policy->allowsProjection()
                    ? 'A política de projeção de mercado está habilitada, mas o número-índice projetado desse mês não foi cadastrado em index_rates (is_projected).'
                    : sprintf(
                        'A política de projeção atual (%s) não permite projeção; configure "market" e cadastre a projeção (ou aprove a política).',
                        $policy->label(),
                    ),
            ));
        }

        if ($rate->isProjected && ! $policy->allowsProjection()) {
            throw new IndexerNotSupportedException(sprintf(
                'O número-índice IPCA de %s está marcado como PROJETADO, mas a política de projeção configurada (%s) não permite uso de projeção (curva em %s). Aprove a política de projeção de mercado para prosseguir.',
                $referenceMonth->toDateString(),
                $policy->label(),
                $contextDate->toDateString(),
            ));
        }

        if ($rate->isProjected && $rate->projectionSeriesId !== null && ! $rate->isApprovedForOperationalUse()) {
            throw new IndexerNotSupportedException(sprintf(
                'O número-índice IPCA projetado de %s pertence a uma série projetada que NÃO está aprovada (status: %s). A curva só pode usar projeção de uma série aprovada via maker/checker (curva em %s).',
                $referenceMonth->toDateString(),
                $rate->projectionSeriesStatus ?? 'desconhecido',
                $contextDate->toDateString(),
            ));
        }

        return new IpcaIndexResolution(
            referenceDate: $referenceMonth,
            value: $rate->value,
            isProjected: $rate->isProjected,
            policy: $policy,
            source: $rate->source,
            projectionSource: $rate->projectionSource,
            projectionReferenceDate: $rate->projectionReferenceDate,
        );
    }
}
