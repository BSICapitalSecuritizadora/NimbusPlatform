<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use Carbon\CarbonImmutable;

/**
 * Uma condição operacional do PU, lida do estado de domínio (Fase 6).
 *
 * Estado de negócio (`type`) e urgência (`severity`) andam separados: a mesma
 * condição pode ser informativa num contexto e pedir ação em outro. A
 * identidade (`incidentKey()`) é estável -- tipo + o fato afetado (conflito,
 * obrigação, emissão, indexador) --, então avaliar de novo a mesma condição
 * nunca vira outro incidente. A data de negócio afetada vai no contexto, não na
 * identidade: o CDI que continua faltando no dia seguinte é a mesma condição.
 */
final readonly class PuOperationalCondition
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public PuOperationalConditionType $type,
        public PuOperationalSeverity $severity,
        public string $reason,
        public ?int $emissionId = null,
        public ?int $curveVersionId = null,
        public ?int $obligationId = null,
        public ?int $settlementConflictId = null,
        public ?string $indexer = null,
        public ?CarbonImmutable $businessDate = null,
        public array $context = [],
    ) {}

    public function incidentKey(): string
    {
        return match (true) {
            $this->settlementConflictId !== null => sprintf('%s:conflict:%d', $this->type->value, $this->settlementConflictId),
            $this->obligationId !== null => sprintf('%s:obligation:%d', $this->type->value, $this->obligationId),
            $this->emissionId !== null => sprintf('%s:emission:%d', $this->type->value, $this->emissionId),
            $this->indexer !== null => sprintf('%s:indexer:%s', $this->type->value, $this->indexer),
            default => sprintf('%s:system', $this->type->value),
        };
    }

    /**
     * Pede ação: vira incidente durável.
     */
    public function alertRequired(): bool
    {
        return $this->severity->isActionable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->incidentKey(),
            'type' => $this->type->value,
            'domain' => $this->type->domain(),
            'check' => $this->type->check(),
            'severity' => $this->severity->value,
            'alert_required' => $this->alertRequired(),
            'reason' => $this->reason,
            'emission_id' => $this->emissionId,
            'curve_version_id' => $this->curveVersionId,
            'obligation_id' => $this->obligationId,
            'settlement_conflict_id' => $this->settlementConflictId,
            'indexer' => $this->indexer,
            'business_date' => $this->businessDate?->toDateString(),
            'context' => $this->context,
        ];
    }
}
