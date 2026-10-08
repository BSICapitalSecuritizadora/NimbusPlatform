<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuObligation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Exportação da conciliação das liquidações do PU (uma linha por obrigação),
 * autorizada no servidor por `pu.reconciliation.export`. Esperado, liquidado e
 * divergência em colunas separadas; componentes do esperado separados.
 */
final class PuReconciliationExportService
{
    /**
     * @return list<array<string, string|int|null>>
     */
    public function rows(Emission $emission, User $user): array
    {
        if (! $user->can(AccessPermission::PuReconciliationExport->value)) {
            throw new AuthorizationException('Sem permissão para exportar a conciliação das liquidações do PU.');
        }

        return EmissionPuObligation::query()
            ->where('emission_id', $emission->id)
            ->with(['currentCalculation.components', 'activeSettlement', 'latestReconciliation'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (EmissionPuObligation $obligation): array {
                $calculation = $obligation->currentCalculation;
                $settlement = $obligation->activeSettlement;
                $components = [];

                foreach (PuObligationComponent::cases() as $component) {
                    $components['expected_'.$component->value] = $calculation?->componentAmount($component);
                }

                return [
                    'obligation_id' => $obligation->id,
                    'obligation_type' => $obligation->obligation_type->value,
                    'contractual_date' => CarbonImmutable::instance($obligation->contractual_date)->toDateString(),
                    'sequence' => $obligation->sequence,
                    'due_date' => $obligation->due_date?->toDateString(),
                    'lifecycle' => $obligation->lifecycle_status->value,
                    'calculation_state' => $obligation->calculation_state->value,
                    'calculation_version' => $calculation?->calculation_version,
                    ...$components,
                    'expected_total' => $calculation?->total_amount !== null ? (string) $calculation->total_amount : null,
                    'settlement_state' => $obligation->settlement_state->value,
                    'settlement_date' => $settlement?->settlement_date?->toDateString(),
                    'settled_amount' => $settlement?->amount !== null ? (string) $settlement->amount : null,
                    'settlement_source' => $settlement?->source->value,
                    'settlement_reference' => $settlement?->external_reference,
                    'reconciliation_status' => $obligation->reconciliation_status->value,
                    'difference' => $obligation->latestReconciliation?->difference !== null ? (string) $obligation->latestReconciliation->difference : null,
                    'divergence' => $obligation->latestReconciliation?->reason,
                ];
            })
            ->values()
            ->all();
    }
}
