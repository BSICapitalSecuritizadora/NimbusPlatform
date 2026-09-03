<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Posição comercial consolidada de uma emissão numa competência: a soma das
 * posições de cada empreendimento elegível.
 *
 * A soma nunca vem sozinha. `constructionsExpected` e `constructionsCovered`
 * acompanham o total porque uma emissão parcialmente coberta somada sem aviso é
 * indistinguível de uma emissão completa — foi exatamente essa confusão que a
 * Fase 0 existe para acabar.
 */
readonly class EmissionSalesPosition extends BaseDTO
{
    /**
     * @param  list<ConstructionSalesPosition>  $positions
     */
    public function __construct(
        public int $emissionId,
        public CarbonImmutable $positionDate,
        public array $positions,
        public int $constructionsExpected,
        public int $constructionsCovered,
        public int $stockUnits,
        public int $financedUnits,
        public int $paidUnits,
        public int $exchangedUnits,
        public int $totalUnits,
        public float $stockValue,
        public float $financedValue,
        public float $paidValue,
        public float $exchangedValue,
    ) {}

    /**
     * @param  list<ConstructionSalesPosition>  $positions
     */
    public static function fromPositions(int $emissionId, CarbonImmutable $positionDate, array $positions): self
    {
        $resolved = array_values(array_filter(
            $positions,
            fn (ConstructionSalesPosition $position): bool => $position->isResolved(),
        ));

        $sum = static fn (string $property): int|float => array_sum(
            array_map(fn (ConstructionSalesPosition $position): int|float => $position->{$property}, $resolved),
        );

        return new self(
            emissionId: $emissionId,
            positionDate: $positionDate,
            positions: $positions,
            constructionsExpected: count(array_filter(
                $positions,
                fn (ConstructionSalesPosition $position): bool => $position->status->isExpected(),
            )),
            constructionsCovered: count($resolved),
            stockUnits: (int) $sum('stockUnits'),
            financedUnits: (int) $sum('financedUnits'),
            paidUnits: (int) $sum('paidUnits'),
            exchangedUnits: (int) $sum('exchangedUnits'),
            totalUnits: (int) $sum('totalUnits'),
            stockValue: round((float) $sum('stockValue'), 2),
            financedValue: round((float) $sum('financedValue'), 2),
            paidValue: round((float) $sum('paidValue'), 2),
            exchangedValue: round((float) $sum('exchangedValue'), 2),
        );
    }

    public function hasData(): bool
    {
        return $this->constructionsCovered > 0;
    }

    public function isFullyCovered(): bool
    {
        return $this->constructionsCovered >= $this->constructionsExpected;
    }

    public function hasCarryForward(): bool
    {
        return $this->carriedForwardPositions() !== [];
    }

    /**
     * @return list<ConstructionSalesPosition>
     */
    public function resolvedPositions(): array
    {
        return array_values(array_filter(
            $this->positions,
            fn (ConstructionSalesPosition $position): bool => $position->isResolved(),
        ));
    }

    /**
     * @return list<ConstructionSalesPosition>
     */
    public function carriedForwardPositions(): array
    {
        return array_values(array_filter(
            $this->positions,
            fn (ConstructionSalesPosition $position): bool => $position->wasCarriedForward(),
        ));
    }

    /**
     * Empreendimentos que deveriam ter posição na competência e não têm.
     *
     * @return list<ConstructionSalesPosition>
     */
    public function missingPositions(): array
    {
        return array_values(array_filter(
            $this->positions,
            fn (ConstructionSalesPosition $position): bool => ! $position->isResolved() && $position->status->isExpected(),
        ));
    }

    /**
     * Competência efetivamente usada por empreendimento; `null` quando não há
     * posição. Nunca omite o empreendimento sem posição — a chave existir com
     * valor nulo é o que distingue "sem informação" de "não faz parte".
     *
     * @return array<int, string|null>
     */
    public function referenceMonthByConstruction(): array
    {
        $months = [];

        foreach ($this->positions as $position) {
            $months[$position->constructionId] = $position->referenceMonthUsedDate();
        }

        return $months;
    }

    /**
     * Quadros que responderam pela competência, na ordem dos empreendimentos.
     *
     * @return Collection<int, SalesBoard>
     */
    public function salesBoards(): Collection
    {
        return collect($this->resolvedPositions())
            ->map(fn (ConstructionSalesPosition $position): ?SalesBoard => $position->salesBoard)
            ->filter(fn (?SalesBoard $salesBoard): bool => $salesBoard instanceof SalesBoard)
            ->values();
    }

    /**
     * @return array{
     *     position_date: string,
     *     constructions_expected: int,
     *     constructions_covered: int,
     *     fully_covered: bool,
     *     carried_forward: bool,
     *     reference_month_by_construction: array<int, string|null>,
     *     missing_construction_ids: list<int>
     * }
     */
    public function coverage(): array
    {
        return [
            'position_date' => $this->positionDate->toDateString(),
            'constructions_expected' => $this->constructionsExpected,
            'constructions_covered' => $this->constructionsCovered,
            'fully_covered' => $this->isFullyCovered(),
            'carried_forward' => $this->hasCarryForward(),
            'reference_month_by_construction' => $this->referenceMonthByConstruction(),
            'missing_construction_ids' => array_values(array_map(
                fn (ConstructionSalesPosition $position): int => $position->constructionId,
                $this->missingPositions(),
            )),
        ];
    }
}
