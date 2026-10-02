<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBridgeBucketRow;
use App\DTOs\SalesBoards\SalesBoardBridgeUnitRow;
use App\DTOs\SalesBoards\SalesBoardCompetenceBridge;
use App\DTOs\SalesBoards\SalesBoardPriorPosition;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\UnitDisplayOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A "Ponte com a competência anterior": reconcilia a posição congelada da
 * anterior com a desta versão, unidade a unidade.
 *
 * Só lê o que foi congelado -- as linhas da âncora vigente
 * ({@see SalesBoardPriorPositionResolver}), as linhas, os movimentos e os
 * avisos desta versão. Nenhuma leitura de contrato, parcela, permuta ou valor
 * vivo: a ponte de uma versão diz o que ela congelou, e é determinística e
 * barata de recalcular.
 *
 * Por unidade, parte da classificação na âncora (ou "fora do inventário") e
 * aplica, em ordem de data, os movimentos extemporâneos, depois os de
 * competência sem posição, depois os do mês: venda leva de estoque a
 * financiado, quitação de financiado a quitado, distrato de financiado ou
 * quitado a estoque, revisão de venda não muda balde. Aplica também a inclusão
 * (ou a reativação) da unidade, a baixa -- pelos avisos congelados com a versão
 * --, e o início e o fim da permuta pelos campos de permuta das linhas. O que
 * não chega à classificação congelada desta versão fica "sem movimento que
 * explique".
 *
 * Não bloqueia nada: correções legítimas sem fato datado (data de venda
 * movida, estorno de pagamento, permuta encerrada depois da anterior) também
 * aparecem aqui, para a Gestão conferir.
 */
class SalesBoardCompetenceBridgeBuilder
{
    private const EXPLANATION_SALE = 'Vendas';

    private const EXPLANATION_SETTLEMENT = 'Quitações';

    private const EXPLANATION_CANCELLATION = 'Distratos';

    private const EXPLANATION_INCLUSION = 'Inclusões no inventário';

    private const EXPLANATION_REACTIVATION = 'Reativações';

    private const EXPLANATION_RETIREMENT = 'Baixas';

    private const EXPLANATION_EXCHANGE_START = 'Início de permuta';

    private const EXPLANATION_EXCHANGE_END = 'Fim de permuta';

    public function __construct(
        private readonly SalesBoardPriorPositionResolver $priorPositionResolver,
    ) {}

    public function forBaseline(SalesBoardCycleBaseline $baseline): SalesBoardCompetenceBridge
    {
        $baseline->loadMissing('cycle');

        return $this->forBaselineWithAnchor($baseline, $this->priorPositionResolver->forCycle($baseline->cycle));
    }

    /**
     * A mesma ponte, para quem já resolveu a âncora vigente da competência --
     * a Validação e a Análise a usam também nos selos dos movimentos.
     */
    public function forBaselineWithAnchor(SalesBoardCycleBaseline $baseline, ?SalesBoardPriorPosition $anchor): SalesBoardCompetenceBridge
    {
        $baseline->loadMissing(['cycle', 'lines', 'movements']);

        /** @var SalesBoardCycle $cycle */
        $cycle = $baseline->cycle;

        $lateCount = $baseline->movements
            ->filter(fn (SalesBoardCycleMovement $movement): bool => $movement->timing !== null)
            ->count();

        if ($anchor === null) {
            return $this->manualAnchor($cycle, $baseline, $lateCount) ?? SalesBoardCompetenceBridge::none($lateCount);
        }

        return $this->fromAnchor($anchor, $cycle, $baseline, $lateCount);
    }

    private function fromAnchor(
        SalesBoardPriorPosition $anchor,
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $baseline,
        int $lateCount,
    ): SalesBoardCompetenceBridge {
        /** @var Collection<int, SalesBoardCycleLine> $lines */
        $lines = $baseline->lines->keyBy(fn (SalesBoardCycleLine $line): int => (int) $line->construction_unit_id);

        /** @var Collection<int, Collection<int, SalesBoardCycleMovement>> $movementsByUnit */
        $movementsByUnit = $baseline->movements->groupBy(fn (SalesBoardCycleMovement $movement): int => (int) $movement->construction_unit_id);

        $warnings = $baseline->frozenWarnings();
        $retired = $this->unitsWithWarning($warnings, SalesBoardIssueCode::UnitRetired);
        $reactivated = $this->unitsWithWarning($warnings, SalesBoardIssueCode::UnitReactivated);

        $anchorDate = $anchor->positionDate->toDateString();
        $windowEnd = CarbonImmutable::parse($cycle->position_date->toDateString())->toDateString();

        $unitIds = collect(array_keys($anchor->lines))
            ->merge($lines->keys())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $counts = [];
        $revaluation = [];
        $explanations = [];
        $unexplained = [];

        foreach (SalesBoardUnitClassification::resolvedCases() as $classification) {
            $counts[$classification->value] = ['entries' => 0, 'exits' => 0, 'unexplained' => 0];
            $revaluation[$classification->value] = 0;
        }

        $explain = function (string $explanation) use (&$explanations): void {
            $explanations[$explanation] = ($explanations[$explanation] ?? 0) + 1;
        };

        foreach ($unitIds as $unitId) {
            $prior = $anchor->line($unitId);
            $line = $lines->get($unitId);

            $start = $prior?->classification;
            $end = $line?->classification;
            $state = $start;
            $holder = ($prior?->holdsSale() ?? false) ? $prior->contractId : null;
            $note = null;

            if (($start === null) && ($line !== null)) {
                $explain(isset($reactivated[$unitId]) ? self::EXPLANATION_REACTIVATION : self::EXPLANATION_INCLUSION);
                $state = SalesBoardUnitClassification::Stock;
            }

            if (($state === SalesBoardUnitClassification::Exchanged)
                && ($end !== SalesBoardUnitClassification::Exchanged)
                && self::within($prior?->exchangeEndedOn, $anchorDate, $windowEnd)) {
                $explain(self::EXPLANATION_EXCHANGE_END);
                $state = SalesBoardUnitClassification::Stock;
                $holder = null;
            }

            $consistent = true;

            foreach ($this->ordered($movementsByUnit->get($unitId, collect())) as $movement) {
                [$state, $holder, $applies] = $this->apply($movement, $state, $holder, $explain);
                $consistent = $consistent && $applies;
            }

            if (($end === SalesBoardUnitClassification::Exchanged)
                && ($state !== SalesBoardUnitClassification::Exchanged)
                && self::within($line->exchange_effective_from?->toDateString(), $anchorDate, $windowEnd)) {
                $explain(self::EXPLANATION_EXCHANGE_START);
                $state = SalesBoardUnitClassification::Exchanged;
            }

            if (($line === null) && ($start !== null)) {
                if ($retired === null) {
                    $note = 'Saída não registrada: a versão é anterior ao registro dos avisos da apuração.';
                } elseif (isset($retired[$unitId])) {
                    $explain(self::EXPLANATION_RETIREMENT);
                    $state = null;
                }
            }

            $explained = ($state === $end) && ($note === null) && $consistent;

            if ($start === $end) {
                if ($start !== null) {
                    $revaluation[$start->value] = self::sum(
                        $revaluation[$start->value],
                        self::difference(IntegerMoney::cents($line?->bucketValue()), $prior?->bucketValueCents),
                    );
                }

                if ($explained) {
                    continue;
                }
            }

            if ($explained) {
                if ($start !== null) {
                    $counts[$start->value]['exits']++;
                }

                if ($end !== null) {
                    $counts[$end->value]['entries']++;
                }

                continue;
            }

            if (($start !== null) && ($start !== $end)) {
                $counts[$start->value]['unexplained']--;
            }

            if (($end !== null) && ($start !== $end)) {
                $counts[$end->value]['unexplained']++;
            }

            $unexplained[] = new SalesBoardBridgeUnitRow(
                constructionUnitId: $unitId,
                unitLabel: self::unitLabel($line?->block ?? $prior?->block, $line?->unit ?? $prior?->unit, $unitId),
                previousClassification: $start,
                currentClassification: $end,
                previousContractCode: $prior?->contractCode,
                currentContractCode: $line?->contract_code,
                note: $note,
                block: $line?->block ?? $prior?->block,
                unit: $line?->unit ?? $prior?->unit,
            );
        }

        usort($unexplained, fn (SalesBoardBridgeUnitRow $left, SalesBoardBridgeUnitRow $right): int => UnitDisplayOrder::compare($left->block, $left->unit, $right->block, $right->unit)
            ?: ($left->constructionUnitId <=> $right->constructionUnitId));

        $buckets = [];

        foreach (SalesBoardUnitClassification::resolvedCases() as $classification) {
            $previousLines = array_filter($anchor->lines, fn ($line): bool => $line->classification === $classification);
            $currentLines = $lines->filter(fn (SalesBoardCycleLine $line): bool => $line->classification === $classification);

            $buckets[] = new SalesBoardBridgeBucketRow(
                classification: $classification,
                previousUnits: count($previousLines),
                entries: $counts[$classification->value]['entries'],
                exits: $counts[$classification->value]['exits'],
                unexplained: $counts[$classification->value]['unexplained'],
                currentUnits: $currentLines->count(),
                previousValueCents: self::total(array_map(fn ($line): ?int => $line->bucketValueCents, $previousLines)),
                currentValueCents: self::total($currentLines->map(fn (SalesBoardCycleLine $line): ?int => IntegerMoney::cents($line->bucketValue()))->all()),
                revaluationCents: $revaluation[$classification->value],
            );
        }

        return new SalesBoardCompetenceBridge(
            anchorKind: SalesBoardCompetenceBridge::ANCHOR_LINES,
            previousLabel: $anchor->label(),
            previousVersionLabel: $anchor->versionLabel(),
            previousPublished: $anchor->isPublished,
            buckets: $buckets,
            unexplainedUnits: $unexplained,
            lateMovementsCount: $lateCount,
            anchorChangedSinceVersion: ($baseline->previous_competence_baseline_id !== null)
                && ((int) $baseline->previous_competence_baseline_id !== $anchor->baselineId),
            explanations: $explanations,
        );
    }

    /**
     * A competência anterior registrada à mão: o quadro manual tem só os
     * totais, e a ponte mostra os totais e a diferença.
     */
    private function manualAnchor(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline, int $lateCount): ?SalesBoardCompetenceBridge
    {
        $previousMonth = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth()->subMonthNoOverflow();

        $board = SalesBoard::query()
            ->where('construction_id', $cycle->construction_id)
            ->whereDate('reference_month', '>=', $previousMonth->toDateString())
            ->whereDate('reference_month', '<=', $previousMonth->endOfMonth()->toDateString())
            ->orderBy('id')
            ->first();

        if (! $board instanceof SalesBoard) {
            return null;
        }

        $rows = [
            [SalesBoardUnitClassification::Stock, $board->stock_units, $board->stock_value, $baseline->stock_units, $baseline->stock_value],
            [SalesBoardUnitClassification::Financed, $board->financed_units, $board->financed_value, $baseline->financed_units, $baseline->financed_value],
            [SalesBoardUnitClassification::Settled, $board->paid_units, $board->paid_value, $baseline->settled_units, $baseline->settled_value],
            [SalesBoardUnitClassification::Exchanged, $board->exchanged_units, $board->exchanged_value, $baseline->exchanged_units, $baseline->exchanged_value],
        ];

        return new SalesBoardCompetenceBridge(
            anchorKind: SalesBoardCompetenceBridge::ANCHOR_MANUAL,
            previousLabel: $previousMonth->format('m/Y'),
            previousVersionLabel: null,
            previousPublished: false,
            buckets: array_map(
                fn (array $row): SalesBoardBridgeBucketRow => new SalesBoardBridgeBucketRow(
                    classification: $row[0],
                    previousUnits: (int) $row[1],
                    entries: null,
                    exits: null,
                    unexplained: null,
                    currentUnits: (int) $row[3],
                    previousValueCents: IntegerMoney::cents($row[2]),
                    currentValueCents: IntegerMoney::cents($row[4]),
                    revaluationCents: null,
                ),
                $rows,
            ),
            unexplainedUnits: [],
            lateMovementsCount: $lateCount,
            anchorChangedSinceVersion: false,
        );
    }

    /**
     * Os movimentos da unidade na ordem em que a ponte os aplica: primeiro os
     * de competência já fechada, depois os de competência sem posição, depois
     * os do mês; dentro de cada grupo, pela data, sem data no fim. No mesmo
     * dia, a venda antes do distrato: a revenda do dia ocupa a unidade, e a
     * venda distratada no mesmo dia nunca a ocupou.
     *
     * @param  Collection<int, SalesBoardCycleMovement>  $movements
     * @return list<SalesBoardCycleMovement>
     */
    private function ordered(Collection $movements): array
    {
        return $movements
            ->sort(function (SalesBoardCycleMovement $left, SalesBoardCycleMovement $right): int {
                $group = static fn (SalesBoardCycleMovement $movement): int => match ($movement->timing) {
                    SalesBoardMovementTiming::Extemporaneous, SalesBoardMovementTiming::SaleRevision => 0,
                    SalesBoardMovementTiming::WithoutPosition => 1,
                    null => 2,
                };
                $type = static fn (SalesBoardCycleMovement $movement): int => match ($movement->movement_type) {
                    SalesBoardMovementType::Sale => 0,
                    SalesBoardMovementType::Cancellation => 1,
                    SalesBoardMovementType::Settlement => 2,
                };

                return [$group($left), $left->event_date === null ? 1 : 0, $left->event_date?->toDateString() ?? '', $type($left), (int) $left->getKey()]
                    <=> [$group($right), $right->event_date === null ? 1 : 0, $right->event_date?->toDateString() ?? '', $type($right), (int) $right->getKey()];
            })
            ->values()
            ->all();
    }

    /**
     * Um movimento sobre o estado da unidade, e se ele cabia nesse estado.
     *
     * A venda leva de estoque a financiado, a quitação de financiado a quitado
     * (do mesmo contrato) e o distrato de financiado ou quitado a estoque (do
     * contrato que segurava a unidade); a revisão de venda não muda balde. O
     * movimento que não cabe no estado -- a venda de uma unidade que a
     * competência anterior já tinha como vendida, como a da venda com a data
     * movida -- não explica nada, e a unidade fica sem explicação mesmo que
     * termine no balde de onde partiu.
     *
     * @param  callable(string): void  $explain
     * @return array{0: SalesBoardUnitClassification|null, 1: int|null, 2: bool}
     */
    private function apply(SalesBoardCycleMovement $movement, ?SalesBoardUnitClassification $state, ?int $holder, callable $explain): array
    {
        $contractId = (int) $movement->contract_id;

        if (($movement->movement_type === SalesBoardMovementType::Sale) && ($movement->timing === SalesBoardMovementTiming::SaleRevision)) {
            return [$state, $holder, true];
        }

        $fits = match ($movement->movement_type) {
            SalesBoardMovementType::Sale => $state === SalesBoardUnitClassification::Stock,
            SalesBoardMovementType::Settlement => ($state === SalesBoardUnitClassification::Financed) && (($holder === null) || ($holder === $contractId)),
            SalesBoardMovementType::Cancellation => in_array($state, [SalesBoardUnitClassification::Financed, SalesBoardUnitClassification::Settled], true)
                && (($holder === null) || ($holder === $contractId)),
        };

        if (! $fits) {
            return [$state, $holder, false];
        }

        return match ($movement->movement_type) {
            SalesBoardMovementType::Sale => (function () use ($contractId, $explain): array {
                $explain(self::EXPLANATION_SALE);

                return [SalesBoardUnitClassification::Financed, $contractId, true];
            })(),
            SalesBoardMovementType::Settlement => (function () use ($contractId, $explain): array {
                $explain(self::EXPLANATION_SETTLEMENT);

                return [SalesBoardUnitClassification::Settled, $contractId, true];
            })(),
            SalesBoardMovementType::Cancellation => (function () use ($explain): array {
                $explain(self::EXPLANATION_CANCELLATION);

                return [SalesBoardUnitClassification::Stock, null, true];
            })(),
        };
    }

    /**
     * As unidades que receberam o aviso, ou `null` quando a versão não
     * registrou avisos.
     *
     * @param  list<array<string, mixed>>|null  $warnings
     * @return array<int, true>|null
     */
    private function unitsWithWarning(?array $warnings, SalesBoardIssueCode $code): ?array
    {
        if ($warnings === null) {
            return null;
        }

        $units = [];

        foreach ($warnings as $warning) {
            if ((($warning['code'] ?? null) === $code->value) && (($warning['construction_unit_id'] ?? null) !== null)) {
                $units[(int) $warning['construction_unit_id']] = true;
            }
        }

        return $units;
    }

    /**
     * A data cai depois do fim da âncora e até o fim desta competência.
     */
    private static function within(?string $day, string $after, string $through): bool
    {
        return ($day !== null) && ($day > $after) && ($day <= $through);
    }

    /**
     * @param  array<int|string, int|null>  $values
     */
    private static function total(array $values): ?int
    {
        $total = 0;

        foreach ($values as $value) {
            if ($value === null) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    private static function difference(?int $current, ?int $previous): ?int
    {
        return ($current === null) || ($previous === null) ? null : $current - $previous;
    }

    private static function sum(?int $total, ?int $value): ?int
    {
        return ($total === null) || ($value === null) ? null : $total + $value;
    }

    private static function unitLabel(?string $block, ?string $unit, int $unitId): string
    {
        $label = trim(sprintf('%s / %s', (string) $block, (string) $unit), ' /');

        return $label === '' ? '#'.$unitId : $label;
    }
}
