<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBaselineDiff;
use App\DTOs\SalesBoards\SalesBoardBucketDelta;
use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardDiffChange;
use App\DTOs\SalesBoards\SalesBoardLineDiff;
use App\DTOs\SalesBoards\SalesBoardMovementDiff;
use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\DTOs\SalesBoards\SalesBoardSnapshotLine;
use App\DTOs\SalesBoards\SalesBoardSnapshotMovement;
use App\Enums\SalesBoardDiffCode;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * O que mudou entre duas versões de uma competência.
 *
 * Um comparador só, porque os dois lados sempre chegam na mesma forma: V1 contra
 * V2, ou uma versão gravada contra o que a fonte viva produziria agora. Ter um
 * comparador por combinação garantiria, com o tempo, que eles discordassem.
 *
 * Toda diferença aponta uma unidade ou um contrato. "Algo mudou" não ajuda
 * ninguém a conferir nada -- e quando o snapshot da unidade não muda mas a fonte
 * dela sim, isso também é dito, em vez de silenciado só porque os números
 * coincidiram.
 */
class SalesBoardBaselineDiffService
{
    public function compare(SalesBoardComparableSnapshot $before, SalesBoardComparableSnapshot $after): SalesBoardBaselineDiff
    {
        return new SalesBoardBaselineDiff(
            lines: $this->diffLines($before, $after),
            movements: $this->diffMovements($before, $after),
            buckets: $this->diffBuckets($before->snapshot, $after->snapshot),
            unitsTotalBefore: $before->snapshot->unitsTotal,
            unitsTotalAfter: $after->snapshot->unitsTotal,
            undeterminedUnitsBefore: $before->snapshot->undeterminedUnits,
            undeterminedUnitsAfter: $after->snapshot->undeterminedUnits,
        );
    }

    /**
     * @return list<SalesBoardLineDiff>
     */
    private function diffLines(SalesBoardComparableSnapshot $before, SalesBoardComparableSnapshot $after): array
    {
        $beforeLines = $before->snapshot->linesByUnit();
        $afterLines = $after->snapshot->linesByUnit();

        $unitIds = array_values(array_unique([...array_keys($beforeLines), ...array_keys($afterLines)]));
        sort($unitIds);

        $diffs = [];

        foreach ($unitIds as $unitId) {
            $old = $beforeLines[$unitId] ?? null;
            $new = $afterLines[$unitId] ?? null;

            if ($old === null) {
                $diffs[] = new SalesBoardLineDiff(
                    constructionUnitId: $unitId,
                    block: $new?->block,
                    unit: $new?->unit,
                    changes: [new SalesBoardDiffChange(
                        code: SalesBoardDiffCode::UnitAdded,
                        after: $new?->classification->label(),
                    )],
                );

                continue;
            }

            if ($new === null) {
                $diffs[] = new SalesBoardLineDiff(
                    constructionUnitId: $unitId,
                    block: $old->block,
                    unit: $old->unit,
                    changes: [new SalesBoardDiffChange(
                        code: SalesBoardDiffCode::UnitRemoved,
                        before: $old->classification->label(),
                    )],
                );

                continue;
            }

            $changes = $this->lineChanges($old, $new);

            /**
             * Nenhum campo congelado mudou, mas a fonte que produziu a linha
             * sim. Isso é reportado, e não engolido: alguém mexeu num fato
             * material da unidade, e quem for aprovar a competência precisa
             * saber disso mesmo que o número tenha ficado igual.
             */
            if ($changes === []) {
                $oldSource = $before->lineSourceFingerprint($unitId);
                $newSource = $after->lineSourceFingerprint($unitId);

                if (($oldSource !== null) && ($newSource !== null) && ($oldSource !== $newSource)) {
                    $changes[] = new SalesBoardDiffChange(code: SalesBoardDiffCode::LineSourceOnlyChanged);
                }
            }

            if ($changes === []) {
                continue;
            }

            $diffs[] = new SalesBoardLineDiff(
                constructionUnitId: $unitId,
                block: $new->block,
                unit: $new->unit,
                changes: $changes,
            );
        }

        return $diffs;
    }

    /**
     * @return list<SalesBoardDiffChange>
     */
    private function lineChanges(SalesBoardSnapshotLine $old, SalesBoardSnapshotLine $new): array
    {
        $changes = [];

        if ($old->classification !== $new->classification) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::ClassificationChanged,
                before: $old->classification->label(),
                after: $new->classification->label(),
            );
        }

        if ($old->contractId !== $new->contractId) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::ContractChanged,
                before: $old->contractCode ?? $this->identifier($old->contractId),
                after: $new->contractCode ?? $this->identifier($new->contractId),
            );
        } elseif ($old->contractCode !== $new->contractCode) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::ContractCodeChanged,
                before: $old->contractCode,
                after: $new->contractCode,
            );
        }

        if ($this->dateChanged($old->contractSaleDate, $new->contractSaleDate)) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::ContractSaleDateChanged,
                before: $this->date($old->contractSaleDate),
                after: $this->date($new->contractSaleDate),
            );
        }

        if ($old->contractSaleValueCents !== $new->contractSaleValueCents) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::ContractSaleValueChanged,
                before: $this->money($old->contractSaleValueCents),
                after: $this->money($new->contractSaleValueCents),
            );
        }

        if (($old->unitReferenceValueCents !== $new->unitReferenceValueCents)
            || ($old->unitReferenceValueSource !== $new->unitReferenceValueSource)
            || $this->dateChanged($old->unitReferenceEffectiveFrom, $new->unitReferenceEffectiveFrom)) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::UnitReferenceValueChanged,
                before: $this->money($old->unitReferenceValueCents),
                after: $this->money($new->unitReferenceValueCents),
            );
        }

        if (($old->settlementState !== $new->settlementState)
            || ($old->settlementInstallmentsTotal !== $new->settlementInstallmentsTotal)
            || ($old->settlementInstallmentsPaid !== $new->settlementInstallmentsPaid)) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::SettlementChanged,
                before: $this->settlement($old),
                after: $this->settlement($new),
            );
        }

        if (($old->exchangeId !== $new->exchangeId)
            || ($old->exchangeValueCents !== $new->exchangeValueCents)
            || $this->dateChanged($old->exchangeEffectiveFrom, $new->exchangeEffectiveFrom)
            || $this->dateChanged($old->exchangeEndedOn, $new->exchangeEndedOn)
            || ($old->exchangeKind !== $new->exchangeKind)) {
            $changes[] = new SalesBoardDiffChange(
                code: SalesBoardDiffCode::ExchangeChanged,
                before: $this->money($old->exchangeValueCents),
                after: $this->money($new->exchangeValueCents),
            );
        }

        return $changes;
    }

    /**
     * @return list<SalesBoardMovementDiff>
     */
    private function diffMovements(SalesBoardComparableSnapshot $before, SalesBoardComparableSnapshot $after): array
    {
        $beforeMovements = $before->snapshot->movementsByKey();
        $afterMovements = $after->snapshot->movementsByKey();

        $keys = array_values(array_unique([...array_keys($beforeMovements), ...array_keys($afterMovements)]));
        sort($keys);

        $diffs = [];

        foreach ($keys as $key) {
            $old = $beforeMovements[$key] ?? null;
            $new = $afterMovements[$key] ?? null;

            if ($old === null) {
                $diffs[] = $this->movementDiff($new, [new SalesBoardDiffChange(
                    code: SalesBoardDiffCode::MovementAdded,
                    after: $this->movementSummary($new),
                )]);

                continue;
            }

            if ($new === null) {
                $diffs[] = $this->movementDiff($old, [new SalesBoardDiffChange(
                    code: SalesBoardDiffCode::MovementRemoved,
                    before: $this->movementSummary($old),
                )]);

                continue;
            }

            $changes = [];

            if ($old->canonicalRow() !== $new->canonicalRow()) {
                $changes[] = new SalesBoardDiffChange(
                    code: SalesBoardDiffCode::MovementChanged,
                    before: $this->movementSummary($old),
                    after: $this->movementSummary($new),
                );
            }

            if ($changes === []) {
                $oldSource = $before->movementSourceFingerprint($key);
                $newSource = $after->movementSourceFingerprint($key);

                if (($oldSource !== null) && ($newSource !== null) && ($oldSource !== $newSource)) {
                    $changes[] = new SalesBoardDiffChange(code: SalesBoardDiffCode::MovementSourceOnlyChanged);
                }
            }

            if ($changes === []) {
                continue;
            }

            $diffs[] = $this->movementDiff($new, $changes);
        }

        return $diffs;
    }

    /**
     * @param  list<SalesBoardDiffChange>  $changes
     */
    private function movementDiff(SalesBoardSnapshotMovement $movement, array $changes): SalesBoardMovementDiff
    {
        return new SalesBoardMovementDiff(
            type: $movement->type,
            contractId: $movement->contractId,
            contractCode: $movement->contractCode,
            constructionUnitId: $movement->constructionUnitId,
            block: $movement->block,
            unit: $movement->unit,
            changes: $changes,
        );
    }

    /**
     * O movimento numa linha legível.
     *
     * A conformidade entra porque é o que muda com mais frequência sem que o
     * valor mude: uma política nova com vigência retroativa reprova uma venda
     * que estava aprovada, e um resumo que só mostrasse data e valor esconderia
     * exatamente isso.
     */
    private function movementSummary(SalesBoardSnapshotMovement $movement): string
    {
        $parts = array_filter([
            $movement->eventDate === null ? null : $movement->eventDate->format('d/m/Y'),
            $movement->saleValueCents === null ? null : 'R$ '.IntegerMoney::format($movement->saleValueCents),
            $movement->conformityStatus?->label(),
            $movement->settlementInstallmentsTotal === null
                ? null
                : $movement->settlementInstallmentsTotal.' parcela(s)',
        ]);

        return $parts === [] ? $movement->type->label() : implode(' · ', $parts);
    }

    /**
     * @return list<SalesBoardBucketDelta>
     */
    private function diffBuckets(SalesBoardSnapshot $before, SalesBoardSnapshot $after): array
    {
        return [
            new SalesBoardBucketDelta('stock', 'Estoque', $before->stockUnits, $after->stockUnits, $before->stockValueCents, $after->stockValueCents),
            new SalesBoardBucketDelta('financed', 'Financiado', $before->financedUnits, $after->financedUnits, $before->financedValueCents, $after->financedValueCents),
            new SalesBoardBucketDelta('settled', 'Quitado', $before->settledUnits, $after->settledUnits, $before->settledValueCents, $after->settledValueCents),
            new SalesBoardBucketDelta('exchanged', 'Permutado', $before->exchangedUnits, $after->exchangedUnits, $before->exchangedValueCents, $after->exchangedValueCents),
        ];
    }

    private function settlement(SalesBoardSnapshotLine $line): ?string
    {
        if ($line->settlementState === null) {
            return null;
        }

        return sprintf(
            '%s (%d/%d)',
            $line->settlementState->label(),
            $line->settlementInstallmentsPaid ?? 0,
            $line->settlementInstallmentsTotal ?? 0,
        );
    }

    private function dateChanged(?CarbonImmutable $before, ?CarbonImmutable $after): bool
    {
        return $before?->toDateString() !== $after?->toDateString();
    }

    private function date(?CarbonImmutable $date): ?string
    {
        return $date?->format('d/m/Y');
    }

    private function money(?int $cents): ?string
    {
        return $cents === null ? null : 'R$ '.IntegerMoney::format($cents);
    }

    private function identifier(?int $id): ?string
    {
        return $id === null ? null : '#'.$id;
    }
}
