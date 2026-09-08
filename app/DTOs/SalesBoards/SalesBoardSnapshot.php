<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\CanonicalDigest;
use Carbon\CarbonImmutable;

/**
 * A posição de uma competência na forma que se congela, se resume e se compara.
 *
 * É o denominador comum entre uma versão gravada e o que a fonte viva produziria
 * agora. Toda a Fase C gira em torno disso: persistir é escrever um snapshot,
 * detectar obsolescência é comparar dois, e o diff é a diferença entre eles.
 *
 * Os totais vêm junto, mas não são a identidade do snapshot -- as linhas e os
 * movimentos são. Um fingerprint feito só sobre os quatro baldes diria que nada
 * mudou quando uma venda passasse de 950.000 para 960.000 sem trocar de balde, e
 * era exatamente a posição que a construtora tinha conferido.
 */
readonly class SalesBoardSnapshot extends BaseDTO
{
    /**
     * @param  list<SalesBoardSnapshotLine>  $lines
     * @param  list<SalesBoardSnapshotMovement>  $movements
     */
    public function __construct(
        public int $constructionId,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $positionDate,
        public int $unitsTotal,
        public int $stockUnits,
        public ?int $stockValueCents,
        public int $financedUnits,
        public ?int $financedValueCents,
        public int $settledUnits,
        public ?int $settledValueCents,
        public int $exchangedUnits,
        public ?int $exchangedValueCents,
        public int $undeterminedUnits,
        public bool $isComplete,
        public array $lines,
        public array $movements,
    ) {}

    public static function fromDerivedPosition(SalesBoardDerivedPosition $position): self
    {
        $movements = [
            ...array_map(SalesBoardSnapshotMovement::fromSale(...), $position->movements->sales),
            ...array_map(SalesBoardSnapshotMovement::fromSettlement(...), $position->movements->settlements),
            ...array_map(SalesBoardSnapshotMovement::fromCancellation(...), $position->movements->cancellations),
        ];

        return new self(
            constructionId: $position->constructionId,
            referenceMonth: $position->referenceMonth,
            positionDate: $position->positionDate,
            unitsTotal: $position->unitsTotal,
            stockUnits: $position->stockUnits,
            stockValueCents: $position->stockValueCents,
            financedUnits: $position->financedUnits,
            financedValueCents: $position->financedValueCents,
            settledUnits: $position->settledUnits,
            settledValueCents: $position->settledValueCents,
            exchangedUnits: $position->exchangedUnits,
            exchangedValueCents: $position->exchangedValueCents,
            undeterminedUnits: $position->undeterminedUnits,
            isComplete: $position->isComplete(),
            lines: array_map(SalesBoardSnapshotLine::fromDerived(...), $position->lines),
            movements: $movements,
        );
    }

    /**
     * Reconstrói o snapshot a partir do que foi gravado.
     *
     * Sem tocar em `contracts`, `construction_units`, políticas ou permutas: se
     * a explicação de uma versão dependesse da fonte viva, ela deixaria de ser
     * uma versão e passaria a ser uma consulta -- e mudaria junto com o mundo,
     * que é precisamente o que congelar existe para impedir.
     */
    public static function fromBaseline(SalesBoardCycleBaseline $baseline): self
    {
        $baseline->loadMissing(['lines', 'movements', 'cycle']);
        $cycle = $baseline->cycle;

        return new self(
            constructionId: (int) $cycle->construction_id,
            referenceMonth: CarbonImmutable::parse($cycle->reference_month->toDateString()),
            positionDate: CarbonImmutable::parse($cycle->position_date->toDateString()),
            unitsTotal: (int) $baseline->units_total,
            stockUnits: (int) $baseline->stock_units,
            stockValueCents: IntegerMoney::cents($baseline->stock_value),
            financedUnits: (int) $baseline->financed_units,
            financedValueCents: IntegerMoney::cents($baseline->financed_value),
            settledUnits: (int) $baseline->settled_units,
            settledValueCents: IntegerMoney::cents($baseline->settled_value),
            exchangedUnits: (int) $baseline->exchanged_units,
            exchangedValueCents: IntegerMoney::cents($baseline->exchanged_value),
            undeterminedUnits: (int) $baseline->undetermined_units,
            isComplete: (bool) $baseline->is_complete,
            lines: $baseline->lines
                ->map(fn (SalesBoardCycleLine $line): SalesBoardSnapshotLine => SalesBoardSnapshotLine::fromPersisted($line))
                ->values()
                ->all(),
            movements: $baseline->movements
                ->map(fn (SalesBoardCycleMovement $movement): SalesBoardSnapshotMovement => SalesBoardSnapshotMovement::fromPersisted($movement))
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<int, SalesBoardSnapshotLine> indexado por `construction_unit_id`
     */
    public function linesByUnit(): array
    {
        $indexed = [];

        foreach ($this->lines as $line) {
            $indexed[$line->constructionUnitId] = $line;
        }

        return $indexed;
    }

    /**
     * @return array<string, SalesBoardSnapshotMovement> indexado por `tipo@contrato`
     */
    public function movementsByKey(): array
    {
        $indexed = [];

        foreach ($this->movements as $movement) {
            $indexed[$movement->key()] = $movement;
        }

        return $indexed;
    }

    /**
     * O snapshot inteiro reduzido a um hash.
     *
     * Totais, linhas e movimentos -- nessa ordem, com as linhas ordenadas por
     * unidade e os movimentos por tipo e contrato. A ordenação é o que garante
     * que a mesma realidade carregada em ordens diferentes produza o mesmo
     * resumo; sem ela, trocar um `ORDER BY` mudaria o hash de toda a carteira.
     */
    public function fingerprint(): string
    {
        $lines = $this->lines;
        usort($lines, fn (SalesBoardSnapshotLine $a, SalesBoardSnapshotLine $b): int => $a->constructionUnitId <=> $b->constructionUnitId);

        $movements = $this->movements;
        usort(
            $movements,
            fn (SalesBoardSnapshotMovement $a, SalesBoardSnapshotMovement $b): int => [$a->type->value, $a->contractId] <=> [$b->type->value, $b->contractId],
        );

        return CanonicalDigest::of([
            'totals' => [CanonicalDigest::row([
                $this->constructionId,
                $this->referenceMonth,
                $this->positionDate,
                $this->unitsTotal,
                $this->stockUnits,
                $this->stockValueCents,
                $this->financedUnits,
                $this->financedValueCents,
                $this->settledUnits,
                $this->settledValueCents,
                $this->exchangedUnits,
                $this->exchangedValueCents,
                $this->undeterminedUnits,
                $this->isComplete,
            ])],
            'lines' => array_map(fn (SalesBoardSnapshotLine $line): string => $line->canonicalRow(), $lines),
            'movements' => array_map(fn (SalesBoardSnapshotMovement $movement): string => $movement->canonicalRow(), $movements),
        ]);
    }
}
