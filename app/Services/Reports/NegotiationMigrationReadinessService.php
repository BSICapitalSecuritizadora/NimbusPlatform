<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ContractStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\Negotiation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Diagnóstico de prontidão para migração de negotiations_source.
 *
 * Não decide automaticamente se a emissão está completa: expõe evidências para
 * que o administrador decida com segurança se todo o histórico de contratos foi
 * migrado.
 *
 * - Nunca altera negotiations_source
 * - Nunca mescla valores legado vs contratos
 * - Apenas leitura, consultas agregadas sem N+1
 */
class NegotiationMigrationReadinessService
{
    /**
     * Analisa uma emissão e retorna métricas estruturadas para o painel de migração.
     *
     * @return array<string, mixed>
     */
    public function analyze(Emission $emission): array
    {
        $emissionId = $emission->getKey();

        // Contract coverage - aggregated counts, single emission scope
        $totalContracts = Contract::query()->forEmission($emissionId)->count();
        $withSaleDate = Contract::query()->forEmission($emissionId)->whereNotNull('sale_date')->count();
        $withoutSaleDate = Contract::query()->forEmission($emissionId)->whereNull('sale_date')->count();
        $withCancellationDate = Contract::query()->forEmission($emissionId)->whereNotNull('cancellation_date')->count();
        $cancelledWithoutCancellationDate = Contract::query()
            ->forEmission($emissionId)
            ->where('status', ContractStatus::Cancelled)
            ->whereNull('cancellation_date')
            ->count();
        $withoutUnitId = Contract::query()->forEmission($emissionId)->whereNull('construction_unit_id')->count();
        $unresolvedUnit = Contract::query()
            ->forEmission($emissionId)
            ->whereNotNull('construction_unit_id')
            ->whereDoesntHave('constructionUnit')
            ->count();

        // Inconsistent emission relationship: construction_id mismatch vs unit OR construction not belonging to emission
        $inconsistent = $this->countInconsistentContracts($emissionId);

        // Unit / construction coverage
        $constructionsCount = Construction::query()->where('emission_id', $emissionId)->count();
        $unitsCount = ConstructionUnit::query()->forEmission($emissionId)->count();
        $unitsWithContracts = ConstructionUnit::query()
            ->forEmission($emissionId)
            ->whereHas('contracts')
            ->count();
        $unitsWithoutContracts = max(0, $unitsCount - $unitsWithContracts);

        // Legacy negotiation comparison
        $legacyRows = Negotiation::query()->where('emission_id', $emissionId)->count();
        $legacyEarliest = Negotiation::query()->where('emission_id', $emissionId)->min('reference_month');
        $legacyLatest = Negotiation::query()->where('emission_id', $emissionId)->max('reference_month');
        $legacyTotals = Negotiation::query()
            ->where('emission_id', $emissionId)
            ->selectRaw('COALESCE(SUM(sales),0) as total_sales')
            ->selectRaw('COALESCE(SUM(cancellations),0) as total_cancellations')
            ->first();
        $legacyTotalSales = (int) ($legacyTotals->total_sales ?? 0);
        $legacyTotalCancellations = (int) ($legacyTotals->total_cancellations ?? 0);

        // Monthly reconciliation: full historical period vs compact display window
        $fullReconciliation = $this->buildMonthlyReconciliation($emissionId, $legacyEarliest, $legacyLatest);

        // Summary across ENTIRE overlapping historical period (full), not just display window
        $reconciliationSummary = $this->buildReconciliationSummary($fullReconciliation);

        // Compact UI table: latest 12 months only
        $displayReconciliation = $fullReconciliation->count() > 12
            ? $fullReconciliation->slice(-12)->values()
            : $fullReconciliation;

        // Readiness must reflect the FULL period — old discrepancies outside display remain visible via status
        $readiness = $this->evaluateReadiness(
            totalContracts: $totalContracts,
            withoutSaleDate: $withoutSaleDate,
            cancelledWithoutCancellationDate: $cancelledWithoutCancellationDate,
            withoutUnitId: $withoutUnitId,
            unresolvedUnit: $unresolvedUnit,
            inconsistent: $inconsistent,
            legacyRows: $legacyRows,
            reconciliation: $fullReconciliation,
        );

        return [
            'emission' => $emission,
            'source' => $emission->negotiations_source ?? Emission::NEGOTIATIONS_SOURCE_LEGACY,
            'is_contracts' => $emission->usesContractNegotiations(),
            'is_legacy' => $emission->usesLegacyNegotiations(),
            'contract_coverage' => [
                'total' => $totalContracts,
                'with_sale_date' => $withSaleDate,
                'without_sale_date' => $withoutSaleDate,
                'with_cancellation_date' => $withCancellationDate,
                'cancelled_without_cancellation_date' => $cancelledWithoutCancellationDate,
                'without_unit_id' => $withoutUnitId,
                'unresolved_unit' => $unresolvedUnit,
                'inconsistent' => $inconsistent,
            ],
            'unit_coverage' => [
                'constructions_count' => $constructionsCount,
                'units_count' => $unitsCount,
                'units_with_contracts' => $unitsWithContracts,
                'units_without_contracts' => $unitsWithoutContracts,
            ],
            'legacy' => [
                'rows' => $legacyRows,
                'earliest' => $legacyEarliest ? CarbonImmutable::parse($legacyEarliest) : null,
                'earliest_label' => $legacyEarliest ? CarbonImmutable::parse($legacyEarliest)->format('m/Y') : '—',
                'latest' => $legacyLatest ? CarbonImmutable::parse($legacyLatest) : null,
                'latest_label' => $legacyLatest ? CarbonImmutable::parse($legacyLatest)->format('m/Y') : '—',
                'total_sales' => $legacyTotalSales,
                'total_cancellations' => $legacyTotalCancellations,
            ],
            'monthly_reconciliation' => $displayReconciliation,
            'monthly_reconciliation_full' => $fullReconciliation,
            'reconciliation_summary' => $reconciliationSummary,
            'readiness' => $readiness,
        ];
    }

    private function countInconsistentContracts(mixed $emissionId): int
    {
        // Contracts where denormalized construction_id diverges from unit's construction_id
        $mismatchUnitConstruction = Contract::query()
            ->forEmission($emissionId)
            ->whereNotNull('construction_unit_id')
            ->whereHas('constructionUnit', function ($q): void {
                $q->whereColumn('construction_units.construction_id', '!=', 'contracts.construction_id');
            })
            ->count();

        // Contracts whose construction does not belong to the emission (should be 0 due to scope, but check explicitly)
        // If forEmission scope is bypassed, this catches drift.
        $mismatchEmission = Contract::query()
            ->whereNotNull('construction_id')
            ->whereHas('construction', fn ($q) => $q->where('emission_id', '!=', $emissionId))
            ->whereHas('constructionUnit', fn ($q) => $q->whereHas('construction', fn ($cq) => $cq->where('emission_id', $emissionId)))
            ->count();

        return $mismatchUnitConstruction + $mismatchEmission;
    }

    /**
     * Summary across entire historical period, compact display keeps only latest 12.
     *
     * @param  Collection<int, array<string, mixed>>  $fullReconciliation
     * @return array<string, mixed>
     */
    private function buildReconciliationSummary(Collection $fullReconciliation): array
    {
        if ($fullReconciliation->isEmpty()) {
            return [
                'period_label' => '—',
                'period_start' => null,
                'period_end' => null,
                'total_months' => 0,
                'compared_months' => 0,
                'compatible_months' => 0,
                'divergent_months' => 0,
            ];
        }

        $first = $fullReconciliation->first();
        $last = $fullReconciliation->last();

        $periodLabel = sprintf('%s a %s', $first['competencia'], $last['competencia']);
        $compared = $fullReconciliation->where('has_legacy', true)->count();
        $divergent = $fullReconciliation->where('is_divergent', true)->count();
        $compatible = $compared - $divergent;

        return [
            'period_label' => $periodLabel,
            'period_start' => $first['competencia'],
            'period_end' => $last['competencia'],
            'total_months' => $fullReconciliation->count(),
            'compared_months' => $compared,
            'compatible_months' => $compatible,
            'divergent_months' => $divergent,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildMonthlyReconciliation(mixed $emissionId, mixed $legacyEarliest, mixed $legacyLatest): Collection
    {
        // DB-agnostic grouping: fetch rows and aggregate in PHP to support SQLite (tests) and MySQL.
        $legacyRows = Negotiation::query()
            ->where('emission_id', $emissionId)
            ->get(['reference_month', 'sales', 'cancellations']);

        $legacyGrouped = $legacyRows
            ->groupBy(fn (Negotiation $n): string => CarbonImmutable::parse($n->reference_month)->format('Y-m'))
            ->map(function (Collection $rows): array {
                return [
                    'legacy_sales' => (int) $rows->sum('sales'),
                    'legacy_cancellations' => (int) $rows->sum('cancellations'),
                ];
            });

        $contracts = Contract::query()
            ->forEmission($emissionId)
            ->get(['sale_date', 'cancellation_date']);

        $contractSalesByMonth = $contracts
            ->filter(fn (Contract $c): bool => $c->sale_date !== null)
            ->groupBy(fn (Contract $c): string => CarbonImmutable::parse($c->sale_date)->format('Y-m'))
            ->map(fn (Collection $rows): int => $rows->count());

        $contractCancellationsByMonth = $contracts
            ->filter(fn (Contract $c): bool => $c->cancellation_date !== null)
            ->groupBy(fn (Contract $c): string => CarbonImmutable::parse($c->cancellation_date)->format('Y-m'))
            ->map(fn (Collection $rows): int => $rows->count());

        // Union of all months where either source has data, sorted — full historical period.
        $allYms = collect()
            ->merge($legacyGrouped->keys())
            ->merge($contractSalesByMonth->keys())
            ->merge($contractCancellationsByMonth->keys())
            ->unique()
            ->sort()
            ->values();

        // If no data at all, return empty
        if ($allYms->isEmpty()) {
            return collect();
        }

        return $allYms->map(function (string $ym) use ($legacyGrouped, $contractSalesByMonth, $contractCancellationsByMonth): array {
            $legacyRow = $legacyGrouped->get($ym);
            $legacySales = $legacyRow ? (int) ($legacyRow['legacy_sales'] ?? 0) : 0;
            $legacyCancellations = $legacyRow ? (int) ($legacyRow['legacy_cancellations'] ?? 0) : 0;
            $hasLegacy = $legacyGrouped->has($ym);

            $contractSales = (int) ($contractSalesByMonth->get($ym) ?? 0);
            $contractCancellations = (int) ($contractCancellationsByMonth->get($ym) ?? 0);

            // Situacao is diagnostic only: does not mutate data
            $situacao = '—';
            $isDivergent = false;

            if ($hasLegacy) {
                if ($legacySales === $contractSales && $legacyCancellations === $contractCancellations) {
                    $situacao = 'Compatível';
                } else {
                    $situacao = 'Divergência';
                    $isDivergent = true;
                }
            } else {
                // No legacy for this month: indicate source without merging
                $situacao = 'Sem histórico legado';
            }

            $competenciaLabel = CarbonImmutable::parse($ym.'-01')->format('m/Y');
            $competenciaRaw = CarbonImmutable::parse($ym.'-01')->toDateString();

            return [
                'competencia' => $competenciaLabel,
                'competencia_raw' => $competenciaRaw,
                'ym' => $ym,
                'legacy_sales' => $legacySales,
                'contract_sales' => $contractSales,
                'legacy_cancellations' => $legacyCancellations,
                'contract_cancellations' => $contractCancellations,
                'has_legacy' => $hasLegacy,
                'situacao' => $situacao,
                'is_divergent' => $isDivergent,
            ];
        })->values();
    }

    /**
     * Evaluate readiness evidence-based. Inventory / unsold units are not failures.
     *
     * @param  Collection<int, array<string, mixed>>  $reconciliation
     * @return array<string, mixed>
     */
    private function evaluateReadiness(
        int $totalContracts,
        int $withoutSaleDate,
        int $cancelledWithoutCancellationDate,
        int $withoutUnitId,
        int $unresolvedUnit,
        int $inconsistent,
        int $legacyRows,
        Collection $reconciliation,
    ): array {
        $issues = [];
        $warnings = [];

        if ($totalContracts === 0 && $legacyRows > 0) {
            $issues[] = 'Nenhum contrato encontrado para esta emissão, mas existem lançamentos manuais legados.';
        }

        if ($withoutSaleDate > 0) {
            $issues[] = sprintf('%d contrato(s) sem data de venda (sale_date).', $withoutSaleDate);
        }

        if ($cancelledWithoutCancellationDate > 0) {
            $issues[] = sprintf('%d contrato(s) com status distratado sem data de distrato (cancellation_date).', $cancelledWithoutCancellationDate);
        }

        if ($withoutUnitId > 0) {
            $issues[] = sprintf('%d contrato(s) sem unidade vinculada (construction_unit_id).', $withoutUnitId);
        }

        if ($unresolvedUnit > 0) {
            $issues[] = sprintf('%d contrato(s) com unidade não encontrada.', $unresolvedUnit);
        }

        if ($inconsistent > 0) {
            $issues[] = sprintf('%d contrato(s) com vínculo empreendimento/emissão inconsistente.', $inconsistent);
        }

        $divergentMonths = $reconciliation->where('is_divergent', true)->count();
        if ($divergentMonths > 0) {
            $warnings[] = sprintf('%d competência(s) com divergência entre legado e contratos.', $divergentMonths);
        }

        // Determine status
        $status = 'pronto';
        $label = 'Pronto para validação';
        $color = 'success';
        $description = 'Nenhuma inconsistência crítica detectada. Verifique as divergências mensais antes de migrar.';

        if (! empty($issues)) {
            $status = 'incompleto';
            $label = 'Incompleto';
            $color = 'danger';
            $description = 'Foram encontradas inconsistências que impedem a migração segura. Corrija os contratos apontados.';
        } elseif (! empty($warnings)) {
            $status = 'atencao';
            $label = 'Atenção';
            $color = 'warning';
            $description = 'Dados de contratos presentes, mas há divergências em relação ao histórico legado. Valide as diferenças.';
        } elseif ($totalContracts === 0) {
            $status = 'incompleto';
            $label = 'Incompleto';
            $color = 'danger';
            $description = 'Nenhum contrato encontrado. A migração não trará dados para Negociações.';
            $issues[] = 'Nenhum contrato cadastrado para esta emissão.';
        } elseif ($legacyRows === 0) {
            // No legacy to compare: still pronto if no issues, but inform
            $description = 'Nenhum histórico legado encontrado. Os contratos serão a única fonte após a migração.';
        }

        return [
            'status' => $status,
            'label' => $label,
            'color' => $color,
            'description' => $description,
            'issues' => $issues,
            'warnings' => $warnings,
            'divergent_months' => $divergentMonths,
            'total_months' => $reconciliation->count(),
        ];
    }
}
