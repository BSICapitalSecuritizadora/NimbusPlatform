<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardGenerationOutcome;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Carbon\CarbonImmutable;

/**
 * O que a geração de um ciclo fez, e por quê.
 *
 * Um resultado bloqueado é tão informativo quanto um bem-sucedido: ele traz o
 * relatório de prontidão inteiro, então quem executou sabe exatamente qual dado
 * falta -- "248 unidades em estoque sem valor" é acionável, "não foi possível
 * gerar" não é.
 */
readonly class SalesBoardGenerationResult extends BaseDTO
{
    public function __construct(
        public SalesBoardGenerationOutcome $outcome,
        public int $constructionId,
        public ?string $constructionName,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $positionDate,
        public ?SalesBoardCycle $cycle = null,
        public ?SalesBoardCycleBaseline $baseline = null,
        public ?SalesBoardReadinessReport $readiness = null,
        public ?SalesBoardDerivedPosition $position = null,
        public ?string $blockedReason = null,
        public bool $dryRun = false,
    ) {}

    public function wasGenerated(): bool
    {
        return $this->outcome === SalesBoardGenerationOutcome::Generated;
    }

    public function isBlocked(): bool
    {
        return $this->outcome === SalesBoardGenerationOutcome::Blocked;
    }

    public function alreadyExisted(): bool
    {
        return $this->outcome === SalesBoardGenerationOutcome::AlreadyExists;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'construction_id' => $this->constructionId,
            'construction' => $this->constructionName,
            'reference_month' => $this->referenceMonth->format('m/Y'),
            'position_date' => $this->positionDate->toDateString(),
            'dry_run' => $this->dryRun,
            'cycle_id' => $this->cycle?->getKey(),
            'baseline_id' => $this->baseline?->getKey(),
            'version' => $this->baseline?->version,
            'blocked_reason' => $this->blockedReason,
            'readiness' => $this->readiness?->toArray(),
        ];
    }
}
