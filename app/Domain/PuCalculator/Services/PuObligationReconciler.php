<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuReconciliationResult;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuSettlement;
use Carbon\CarbonImmutable;

/**
 * Compara o valor esperado oficial de uma obrigação com a liquidação vigente.
 *
 * Função pura dos fatos: o cálculo esperado vigente (imutável), a liquidação
 * vigente e os conflitos em aberto. Nada é escrito aqui; quem chama guarda o
 * resultado com a proveniência. Por isso a conciliação pode ser refeita a
 * qualquer momento e sempre chega ao mesmo lugar.
 *
 * Regra monetária: igualdade EXATA na escala monetária canônica (2 casas), a do
 * cronograma e da liquidação. Não há tolerância de liquidação no produto, e a
 * Fase 5 não inventa uma. Diferença = liquidado − esperado.
 *
 * Liquidação é fechada. Valor diferente é divergência -- nunca liquidação parcial,
 * saldo residual, nova obrigação, multa ou mudança de principal. A data também é
 * comparada (a liquidação B3 acontece na data do evento); componentes só quando a
 * origem os informou -- só o total, e a conciliação fica no total.
 */
final class PuObligationReconciler
{
    public const REASON_AWAITING_SETTLEMENT = 'awaiting_settlement';

    public const REASON_OBLIGATION_SUPERSEDED = 'obligation_superseded';

    public const REASON_OPEN_CONFLICT = 'open_settlement_conflict';

    public const REASON_NO_EXPECTED_CALCULATION = 'no_expected_calculation';

    public function evaluate(
        EmissionPuObligation $obligation,
        ?EmissionPuObligationCalculation $calculation,
        ?EmissionPuSettlement $settlement,
        int $openConflicts = 0,
        ?EmissionPuObligationCalculation $calculationAtSettlement = null,
    ): PuReconciliationResult {
        $calculationId = $calculation?->id;
        $settlementId = $settlement?->id;
        $expected = $calculation?->isComplete() ? (string) $calculation->total_amount : null;
        $actual = $settlement !== null ? (string) $settlement->amount : null;

        if ($openConflicts > 0) {
            return new PuReconciliationResult(
                status: PuReconciliationStatus::Conflict,
                reason: self::REASON_OPEN_CONFLICT,
                calculationId: $calculationId,
                settlementId: $settlementId,
                expectedTotal: $expected,
                actualTotal: $actual,
                divergence: ['open_conflicts' => $openConflicts],
            );
        }

        if (! $settlement instanceof EmissionPuSettlement) {
            return new PuReconciliationResult(
                status: $obligation->isActive() ? PuReconciliationStatus::Pending : PuReconciliationStatus::NotApplicable,
                reason: $obligation->isActive() ? self::REASON_AWAITING_SETTLEMENT : self::REASON_OBLIGATION_SUPERSEDED,
                calculationId: $calculationId,
                expectedTotal: $expected,
            );
        }

        if (! $obligation->isActive()) {
            return $this->indeterminate(self::REASON_OBLIGATION_SUPERSEDED, $calculationId, $settlementId, $expected, $actual);
        }

        $state = $obligation->calculation_state;

        if (! $state instanceof PuObligationCalculationState || ! $state->isTrustworthy()) {
            return $this->indeterminate($state?->value ?? self::REASON_NO_EXPECTED_CALCULATION, $calculationId, $settlementId, $expected, $actual);
        }

        if (! $calculation instanceof EmissionPuObligationCalculation || $expected === null) {
            return $this->indeterminate(self::REASON_NO_EXPECTED_CALCULATION, $calculationId, $settlementId, $expected, $actual);
        }

        $difference = bcsub((string) $actual, $expected, 2);
        $kinds = [];
        $divergence = [];

        if (bccomp($difference, '0', 2) !== 0) {
            $kinds[] = 'amount';
            $divergence['amount'] = ['expected' => $expected, 'actual' => $actual, 'difference' => $difference];
        }

        $expectedDate = CarbonImmutable::instance($calculation->due_date)->toDateString();
        $actualDate = CarbonImmutable::instance($settlement->settlement_date)->toDateString();

        if ($expectedDate !== $actualDate) {
            $kinds[] = 'date';
            $divergence['date'] = ['expected' => $expectedDate, 'actual' => $actualDate];
        }

        $provided = $settlement->providedComponents();

        if ($provided === null) {
            $divergence['component_reconciliation'] = 'total_only';
        } else {
            $divergence['component_reconciliation'] = 'compared';
            $componentDifferences = $this->componentDifferences($calculation, $provided);

            if ($componentDifferences !== []) {
                $kinds[] = 'components';
                $divergence['components'] = $componentDifferences;
            }
        }

        if ($calculationAtSettlement instanceof EmissionPuObligationCalculation && $calculationAtSettlement->id !== $calculation->id) {
            $before = $calculationAtSettlement->isComplete() ? (string) $calculationAtSettlement->total_amount : null;
            $divergence['calculation_changed_after_settlement'] = [
                'calculation_at_settlement_id' => $calculationAtSettlement->id,
                'expected_total_at_settlement' => $before,
                'current_calculation_id' => $calculation->id,
                'current_expected_total' => $expected,
                'amount_changed' => $before === null || bccomp($before, $expected, 2) !== 0,
            ];
        }

        $divergence = ['kinds' => $kinds, ...$divergence];

        return new PuReconciliationResult(
            status: $kinds === [] ? PuReconciliationStatus::Matched : PuReconciliationStatus::Divergent,
            reason: $kinds === [] ? null : implode(',', $kinds),
            calculationId: $calculation->id,
            settlementId: $settlement->id,
            expectedTotal: $expected,
            actualTotal: $actual,
            difference: $difference,
            divergence: $divergence,
        );
    }

    /**
     * @param  array<string, string>  $provided
     * @return array<string, array{expected: string, actual: string, difference: string}>
     */
    private function componentDifferences(EmissionPuObligationCalculation $calculation, array $provided): array
    {
        $calculation->loadMissing('components');
        $keys = array_unique([
            ...array_keys($provided),
            ...$calculation->components->map(fn ($component): string => $component->component->value)->all(),
        ]);
        sort($keys);
        $differences = [];

        foreach ($keys as $key) {
            $component = PuObligationComponent::tryFrom((string) $key);
            $expected = $component !== null ? ($calculation->componentAmount($component) ?? '0.00') : '0.00';
            $actual = bcadd((string) ($provided[$key] ?? '0'), '0', 2);
            $difference = bcsub($actual, $expected, 2);

            if (bccomp($difference, '0', 2) !== 0) {
                $differences[(string) $key] = ['expected' => $expected, 'actual' => $actual, 'difference' => $difference];
            }
        }

        return $differences;
    }

    private function indeterminate(string $reason, ?int $calculationId, ?int $settlementId, ?string $expected, ?string $actual): PuReconciliationResult
    {
        return new PuReconciliationResult(
            status: PuReconciliationStatus::Indeterminate,
            reason: $reason,
            calculationId: $calculationId,
            settlementId: $settlementId,
            expectedTotal: $expected,
            actualTotal: $actual,
        );
    }
}
