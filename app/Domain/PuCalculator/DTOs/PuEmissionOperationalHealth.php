<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Enums\PuOperationalEligibility;

/**
 * Retrato operacional de uma emissão (Fase 6): curva oficial, atualidade,
 * obrigações, liquidação, conciliação e trabalho pendente, todos lidos das
 * fontes que já decidem cada coisa. Estruturado para teste, comando e a futura
 * tela -- nada aqui é texto montado para leitura humana.
 */
final readonly class PuEmissionOperationalHealth
{
    /**
     * @param  array<string, int>  $obligations  contagens (por estado de cálculo, liquidação, conciliação, vencidas)
     * @param  array<string, int>  $refresh  pedidos de atualização abertos por situação
     * @param  array<string, string|null>  $lastSuccessfulOperations  homologação, extensão, atualização das obrigações
     * @param  list<PuOperationalCondition>  $conditions
     */
    public function __construct(
        public int $emissionId,
        public ?string $emissionName,
        public PuOperationalEligibility $eligibility,
        public ?PuIndexer $indexer,
        public bool $indexerOperational,
        public ?PuOfficialCurveStatus $officialStatus,
        public array $obligations,
        public array $refresh,
        public array $lastSuccessfulOperations,
        public array $conditions,
    ) {}

    public function freshness(): ?PuOfficialCurveFreshness
    {
        return $this->officialStatus?->freshness;
    }

    /**
     * O motivo de maior urgência que impede a operação normal, ou nulo.
     */
    public function blockingReason(): ?string
    {
        $actionable = array_values(array_filter($this->conditions, fn (PuOperationalCondition $condition): bool => $condition->alertRequired()));
        usort($actionable, fn (PuOperationalCondition $a, PuOperationalCondition $b): int => $b->severity->rank() <=> $a->severity->rank());

        return $actionable[0]->reason ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $status = $this->officialStatus;

        return [
            'emission_id' => $this->emissionId,
            'emission_name' => $this->emissionName,
            'eligibility' => $this->eligibility->value,
            'indexer' => $this->indexer?->value,
            'indexer_operational' => $this->indexerOperational,
            'official_version_id' => $status?->versionId,
            'official_calculation_version' => $status?->calculationVersion,
            'freshness' => $status?->freshness->value,
            'realized_through' => $status?->realizedThrough?->toDateString(),
            'expected_realized_through' => $status?->expectedRealizedThrough?->toDateString(),
            'curve_end_date' => $status?->curveEndDate?->toDateString(),
            'next_required_rate_date' => $status?->nextRequiredRateDate?->toDateString(),
            'latest_realized_rate_date' => $status?->latestRealizedRateDate?->toDateString(),
            'expected_latest_rate_date' => $status?->expectedLatestRateDate?->toDateString(),
            'reprocessing_required' => $status?->freshness === PuOfficialCurveFreshness::ReprocessingRequired,
            'reprocessing_from' => $status?->reprocessingFrom?->toDateString(),
            'contractual_change_from' => $status?->contractualChangeFrom?->toDateString(),
            'blocking_reason' => $this->blockingReason(),
            'obligations' => $this->obligations,
            'refresh' => $this->refresh,
            'last_successful_operations' => $this->lastSuccessfulOperations,
            'conditions' => array_map(fn (PuOperationalCondition $condition): array => $condition->toArray(), $this->conditions),
        ];
    }
}
