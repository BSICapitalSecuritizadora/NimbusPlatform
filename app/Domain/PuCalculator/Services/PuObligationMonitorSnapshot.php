<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlementConflict;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Situações estruturadas da Fase 5 para o monitoramento da Fase 6: obrigação
 * vencida sem liquidação, liquidação divergente, conciliação indeterminada,
 * conflito em aberto, efeito financeiro não suportado e esperado em
 * reprocessamento. Só leitura -- nenhum alerta, canal ou escalonamento aqui.
 *
 * "Vencida" usa o dia de negócio (BRT) e é calculada na leitura: não é gravada na
 * conciliação, que não muda com a passagem do tempo.
 */
final class PuObligationMonitorSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(?int $emissionId = null): array
    {
        $today = BusinessTime::dateString();
        $base = fn (): Builder => EmissionPuObligation::query()
            ->when($emissionId !== null, fn (Builder $query): Builder => $query->where('emission_id', $emissionId))
            ->active();
        $ids = fn (Builder $query): array => $query->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [
            'business_date' => $today,
            'unsettled_past_due' => $ids($base()
                ->where('settlement_state', PuSettlementState::Unsettled->value)
                ->whereDate('due_date', '<', $today)),
            'settlement_divergent' => $ids($base()->where('reconciliation_status', PuReconciliationStatus::Divergent->value)),
            'reconciliation_indeterminate' => $ids($base()->where('reconciliation_status', PuReconciliationStatus::Indeterminate->value)),
            'settlement_conflict_open' => EmissionPuSettlementConflict::query()
                ->when($emissionId !== null, fn (Builder $query): Builder => $query->where('emission_id', $emissionId))
                ->open()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'unsupported_financial_effect' => $ids($base()->where('calculation_state', PuObligationCalculationState::Unsupported->value)),
            'reprocessing_required' => $ids($base()->where('calculation_state', PuObligationCalculationState::ReprocessingRequired->value)),
        ];
    }
}
