<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\Enums\GuaranteeCoverageStatus;
use App\Models\GuaranteeSnapshot;
use Illuminate\Support\Collection;

/**
 * Posição consolidada das garantias de uma emissão numa competência — o que
 * alimenta o resumo executivo, o snapshot mensal e o relatório.
 */
readonly class EmissionGuaranteePositionData extends BaseDTO
{
    /**
     * @param  Collection<int, GuaranteePositionData>  $positions
     * @param  array<int, string>  $pendingSources  rótulos das fontes sem dado na competência
     */
    public function __construct(
        public string $referenceMonth,
        public Collection $positions,
        public ?float $totalGrossValue,
        public ?float $totalEligibleValue,
        public ?float $totalRequiredValue,
        public ?float $outstandingBalance,
        public ?float $coverageRatio,
        public ?float $requiredRatio,
        public ?float $surplusDeficit,
        public GuaranteeCoverageStatus $coverageStatus,
        public int $activeGuaranteesCount,
        public array $pendingSources = [],
    ) {}

    public function referenceMonthLabel(): string
    {
        return GuaranteeSnapshot::formatReferenceMonthForDisplay($this->referenceMonth);
    }

    public function hasPendingValues(): bool
    {
        return $this->positions->contains(fn (GuaranteePositionData $position): bool => $position->isPending());
    }

    /**
     * Garantias que dependem de digitação nesta competência — a lista que a
     * atualização mensal inteligente (§22) pede ao usuário.
     *
     * @return Collection<int, GuaranteePositionData>
     */
    public function pendingPositions(): Collection
    {
        return $this->positions->filter(fn (GuaranteePositionData $position): bool => $position->isPending())->values();
    }

    /**
     * @return Collection<int, GuaranteePositionData>
     */
    public function breachingPositions(): Collection
    {
        return $this->positions->filter(fn (GuaranteePositionData $position): bool => $position->hasDeficit())->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'total_gross_value' => $this->totalGrossValue,
            'total_eligible_value' => $this->totalEligibleValue,
            'total_required_value' => $this->totalRequiredValue,
            'outstanding_balance' => $this->outstandingBalance,
            'coverage_ratio' => $this->coverageRatio,
            'required_ratio' => $this->requiredRatio,
            'surplus_deficit' => $this->surplusDeficit,
            'coverage_status' => $this->coverageStatus,
            'active_guarantees_count' => $this->activeGuaranteesCount,
            'pending_sources' => $this->pendingSources,
        ];
    }
}
