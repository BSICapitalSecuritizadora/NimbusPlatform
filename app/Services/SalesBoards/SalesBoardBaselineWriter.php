<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardSnapshotLine;
use App\DTOs\SalesBoards\SalesBoardSnapshotMovement;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\User;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardFrozenWarnings;
use Carbon\CarbonImmutable;

/**
 * Grava uma versão congelada: o cabeçalho, as linhas e os movimentos.
 *
 * Um lugar só porque geração, recálculo e abertura da retificação escrevem
 * exatamente a mesma coisa -- a diferença entre elas é quando e por quê, não o
 * que. A versão também registra contra qual versão da competência anterior os
 * extemporâneos foram apurados (`previous_competence_baseline_id`) e quais
 * competências canceladas ela absorveu (`absorbed_cancelled_months`) -- a
 * estrutura da cadeia, fora do fingerprint --, e cada movimento o seu
 * `timing`. Duas rotinas de escrita acabariam divergindo num campo, e o campo
 * seria descoberto meses depois numa versão que ninguém consegue mais
 * reproduzir.
 *
 * Não decide nada. Não classifica, não soma, não avalia conformidade: recebe o
 * que a derivação apurou e persiste fielmente. Toda a aritmética já aconteceu.
 * Os avisos da apuração ({@see SalesBoardFrozenWarnings::fromPosition()}) também
 * chegam prontos e são gravados como vieram, ao lado da versão: são o registro do
 * que o Nimbus sinalizou quando a posição foi congelada, e não decidem nada.
 * Todo chamador passa a lista -- a vazia quando não há aviso --, porque uma
 * versão nova gravada sem ela ficaria "não registrada" para sempre.
 *
 * Quem chama é responsável pela transação. A escrita é inútil pela metade: um
 * ciclo sem versão, ou uma versão com parte das unidades, seria pior que
 * nenhuma -- pareceria uma posição.
 */
class SalesBoardBaselineWriter
{
    /**
     * Linhas por lote de inserção. Uma obra grande tem centenas de unidades, e
     * um `INSERT` por linha transformaria a geração numa espera visível.
     */
    private const CHUNK = 500;

    /**
     * @param  list<array<string, mixed>>  $frozenWarnings  os avisos da posição, de {@see SalesBoardFrozenWarnings::fromPosition()}
     */
    public function write(
        SalesBoardCycle $cycle,
        int $version,
        SalesBoardComparableSnapshot $comparable,
        string $sourceFingerprint,
        ?User $actor,
        ?string $reason,
        array $frozenWarnings,
    ): SalesBoardCycleBaseline {
        $snapshot = $comparable->snapshot;
        $now = CarbonImmutable::now();

        $baseline = SalesBoardCycleBaseline::query()->create([
            'sales_board_cycle_id' => $cycle->getKey(),
            'version' => $version,
            'units_total' => $snapshot->unitsTotal,
            'stock_units' => $snapshot->stockUnits,
            'stock_value' => $this->decimal($snapshot->stockValueCents),
            'financed_units' => $snapshot->financedUnits,
            'financed_value' => $this->decimal($snapshot->financedValueCents),
            'settled_units' => $snapshot->settledUnits,
            'settled_value' => $this->decimal($snapshot->settledValueCents),
            'exchanged_units' => $snapshot->exchangedUnits,
            'exchanged_value' => $this->decimal($snapshot->exchangedValueCents),
            'undetermined_units' => $snapshot->undeterminedUnits,
            'is_complete' => $snapshot->isComplete,
            'warnings' => array_values($frozenWarnings),
            'previous_competence_baseline_id' => $snapshot->previousCompetenceBaselineId,
            'absorbed_cancelled_months' => $snapshot->absorbedCancelledMonths,
            'source_fingerprint' => $sourceFingerprint,
            'snapshot_fingerprint' => $snapshot->fingerprint(),
            'computed_at' => $now,
            'computed_by_id' => $actor?->getKey(),
            'reason' => $reason,
            'is_stale' => false,
            'stale_impact' => SalesBoardStaleImpact::None,
            'last_checked_at' => $now,
            'last_observed_source_fingerprint' => $sourceFingerprint,
            'last_observed_snapshot_fingerprint' => $snapshot->fingerprint(),
        ]);

        $this->writeLines($baseline, $comparable, $now);
        $this->writeMovements($baseline, $comparable, $now);

        return $baseline;
    }

    private function writeLines(SalesBoardCycleBaseline $baseline, SalesBoardComparableSnapshot $comparable, CarbonImmutable $now): void
    {
        $rows = array_map(
            fn (SalesBoardSnapshotLine $line): array => [
                'sales_board_cycle_baseline_id' => $baseline->getKey(),
                'construction_unit_id' => $line->constructionUnitId,
                'block' => $line->block,
                'unit' => $line->unit,
                'classification' => $line->classification->value,
                'contract_id' => $line->contractId,
                'contract_code' => $line->contractCode,
                'contract_sale_date' => $line->contractSaleDate?->toDateString(),
                'contract_sale_value' => $this->decimal($line->contractSaleValueCents),
                'unit_reference_value' => $this->decimal($line->unitReferenceValueCents),
                'unit_reference_value_source' => $line->unitReferenceValueSource?->value,
                'unit_reference_value_effective_from' => $line->unitReferenceEffectiveFrom?->toDateString(),
                'settlement_state' => $line->settlementState?->value,
                'settlement_installments_total' => $line->settlementInstallmentsTotal,
                'settlement_installments_paid' => $line->settlementInstallmentsPaid,
                'construction_unit_exchange_id' => $line->exchangeId,
                'exchange_value' => $this->decimal($line->exchangeValueCents),
                'exchange_effective_from' => $line->exchangeEffectiveFrom?->toDateString(),
                'exchange_ended_on' => $line->exchangeEndedOn?->toDateString(),
                'exchange_kind' => $line->exchangeKind?->value,
                'source_fingerprint' => $comparable->lineSourceFingerprint($line->constructionUnitId),
                'snapshot_fingerprint' => $line->fingerprint(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $comparable->snapshot->lines,
        );

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            SalesBoardCycleLine::query()->insert($chunk);
        }
    }

    private function writeMovements(SalesBoardCycleBaseline $baseline, SalesBoardComparableSnapshot $comparable, CarbonImmutable $now): void
    {
        $rows = array_map(
            fn (SalesBoardSnapshotMovement $movement): array => [
                'sales_board_cycle_baseline_id' => $baseline->getKey(),
                'movement_type' => $movement->type->value,
                'timing' => $movement->timing?->value,
                'construction_unit_id' => $movement->constructionUnitId,
                'block' => $movement->block,
                'unit' => $movement->unit,
                'contract_id' => $movement->contractId,
                'contract_code' => $movement->contractCode,
                'event_date' => $movement->eventDate?->toDateString(),
                'sale_date' => $movement->saleDate?->toDateString(),
                'sale_value' => $this->decimal($movement->saleValueCents),
                'cancellation_date' => $movement->cancellationDate?->toDateString(),
                'settlement_installments_total' => $movement->settlementInstallmentsTotal,
                'unit_reference_value' => $this->decimal($movement->unitReferenceValueCents),
                'unit_reference_value_source' => $movement->unitReferenceValueSource?->value,
                'unit_reference_value_effective_from' => $movement->unitReferenceEffectiveFrom?->toDateString(),
                'sales_discount_policy_id' => $movement->salesDiscountPolicyId,
                'authorized_discount_basis_points' => $movement->authorizedDiscountBasisPoints,
                'minimum_authorized_value' => $this->decimal($movement->minimumAuthorizedValueCents),
                'effective_discount_basis_points' => $movement->effectiveDiscountBasisPoints,
                'difference_value' => $this->decimal($movement->differenceCents),
                'conformity_status' => $movement->conformityStatus?->value,
                'conformity_reason' => $movement->conformityReason,
                'source_fingerprint' => $comparable->movementSourceFingerprint($movement->key()),
                'snapshot_fingerprint' => $movement->fingerprint(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $comparable->snapshot->movements,
        );

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            SalesBoardCycleMovement::query()->insert($chunk);
        }
    }

    /**
     * Centavos para a coluna `decimal(15,2)`, sem passar por `float`.
     *
     * `null` continua `null`: um valor desconhecido não vira `0,00` no caminho
     * para o banco.
     */
    private function decimal(?int $cents): ?string
    {
        return $cents === null ? null : IntegerMoney::decimalString($cents);
    }
}
