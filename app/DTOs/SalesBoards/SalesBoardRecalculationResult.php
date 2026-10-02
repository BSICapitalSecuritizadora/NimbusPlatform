<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Models\SalesBoardCycleBaseline;

/**
 * O que o recálculo de um ciclo fez.
 *
 * A versão anterior vem junto com a nova de propósito: o valor do recálculo não
 * está na versão criada, está no par -- é a comparação entre as duas que explica
 * a mudança, e é ela que a Fase E vai usar para decidir se a construtora precisa
 * conferir de novo. `chainChange` diz, quando for o caso, que a cadeia de
 * competências mudou entre as duas ({@see SalesBoardChainStructure}).
 */
readonly class SalesBoardRecalculationResult extends BaseDTO
{
    public function __construct(
        public SalesBoardRecalculationOutcome $outcome,
        public SalesBoardCycleBaseline $previousBaseline,
        public ?SalesBoardCycleBaseline $baseline,
        public ?string $reason,
        public SalesBoardReadinessReport $readiness,
        public ?SalesBoardBaselineDiff $diff = null,
        public ?string $blockedReason = null,
        public ?string $chainChange = null,
    ) {}

    public function createdNewVersion(): bool
    {
        return $this->outcome === SalesBoardRecalculationOutcome::Recalculated;
    }

    public function isBlocked(): bool
    {
        return $this->outcome === SalesBoardRecalculationOutcome::Blocked;
    }

    public function message(): string
    {
        return match ($this->outcome) {
            SalesBoardRecalculationOutcome::Recalculated => sprintf(
                'Versão %s criada a partir da %s. %s',
                $this->baseline?->versionLabel() ?? '—',
                $this->previousBaseline->versionLabel(),
                implode(' ', array_filter([$this->chainChange, $this->diff?->summary()])),
            ),
            SalesBoardRecalculationOutcome::Unchanged => 'Nada mudou desde a versão atual: nenhuma versão nova foi criada.',
            SalesBoardRecalculationOutcome::Blocked => (string) $this->blockedReason,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'previous_baseline_id' => $this->previousBaseline->getKey(),
            'previous_version' => $this->previousBaseline->version,
            'baseline_id' => $this->baseline?->getKey(),
            'version' => $this->baseline?->version,
            'reason' => $this->reason,
            'blocked_reason' => $this->blockedReason,
            'chain_change' => $this->chainChange,
            'message' => $this->message(),
            'diff' => $this->diff?->toArray(),
        ];
    }
}
