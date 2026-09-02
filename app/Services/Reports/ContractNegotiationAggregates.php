<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\Negotiation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Contract-derived Negociações aggregation (sale_date / cancellation_date).
 *
 * Contract: Venda = contracts.sale_date ∈ month, Distrato = contracts.cancellation_date ∈ month.
 * Aggregation key = (emission_id, construction_id, reference_month=YYYY-MM-01).
 * No negotiations table read in contracts mode.
 *
 * Efficient grouped queries: UNION ALL of vendas/distratos truncated to month,
 * then outer GROUP BY (emission, construction, YM). Uses DB-side aggregation
 * with no model hydration for the list view. Detail drill-down reuses
 * Contract::forEmission scope with indexed sale_date/cancellation_date filters.
 */
class ContractNegotiationAggregates
{
    /**
     * DB-side grouped query for contract-derived negotiations.
     *
     * Returns a QueryBuilder (DB::query) selecting:
     * - emission_id, construction_id, reference_month (Y-m-01)
     * - sales (SUM vendas), cancellations (SUM distratos)
     * - emission_name, development_name
     *
     * Filters: emission_id, construction_id, reference_month (Y-m-01 or m/Y), tipo (Venda/Distrato).
     * The builder is suitable for Filament table pagination/sort (defaultSort reference_month desc).
     */
    public function aggregatedQuery(array $filters = []): QueryBuilder
    {
        $emissionId = $filters['emission_id'] ?? null;
        $constructionId = $filters['construction_id'] ?? null;
        $referenceMonth = $filters['reference_month'] ?? null;
        $tipo = $filters['tipo'] ?? null;

        // Support nested Filament filter format ['emission_id' => ['value' => 5]]
        $emissionId = $this->normalizeFilterValue($emissionId);
        $constructionId = $this->normalizeFilterValue($constructionId);
        $referenceMonth = $this->normalizeFilterValue($referenceMonth);
        $tipo = $this->normalizeFilterValue($tipo);

        if (filled($referenceMonth)) {
            $referenceMonth = Negotiation::normalizeReferenceMonth($referenceMonth) ?? $referenceMonth;
        }

        $monthSaleExpr = $this->monthTruncateExpression('sale_date');
        $monthCancelExpr = $this->monthTruncateExpression('cancellation_date');

        $salesSub = DB::table('contracts')
            ->selectRaw("construction_id, {$monthSaleExpr} as reference_month, COUNT(*) as vendas, 0 as distratos")
            ->whereNotNull('sale_date')
            ->whereNull('deleted_at')
            ->groupBy('construction_id', 'reference_month');

        $cancelSub = DB::table('contracts')
            ->selectRaw("construction_id, {$monthCancelExpr} as reference_month, 0 as vendas, COUNT(*) as distratos")
            ->whereNotNull('cancellation_date')
            ->whereNull('deleted_at')
            ->groupBy('construction_id', 'reference_month');

        $union = $salesSub->unionAll($cancelSub);

        $query = DB::query()->fromSub($union, 'q')
            ->join('constructions as c', 'c.id', '=', 'q.construction_id')
            ->join('emissions as e', 'e.id', '=', 'c.emission_id')
            ->select([
                'c.emission_id',
                'q.construction_id',
                'q.reference_month',
            ])
            ->selectRaw('SUM(q.vendas) as sales')
            ->selectRaw('SUM(q.distratos) as cancellations')
            ->selectRaw('MAX(e.name) as emission_name')
            ->selectRaw('MAX(c.development_name) as development_name')
            ->groupBy('c.emission_id', 'q.construction_id', 'q.reference_month');

        if (filled($emissionId)) {
            $query->where('c.emission_id', $emissionId);
        }

        if (filled($constructionId)) {
            $query->where('q.construction_id', $constructionId);
        }

        if (filled($referenceMonth)) {
            $query->where('q.reference_month', $referenceMonth);
        }

        if ($tipo === 'Venda') {
            $query->havingRaw('SUM(q.vendas) > 0');
        } elseif ($tipo === 'Distrato') {
            $query->havingRaw('SUM(q.distratos) > 0');
        }

        return $query;
    }

    /**
     * Detail drill-down: contracts for a specific (emission, construction, month, tipo).
     *
     * Returns Eloquent Builder<Contract> with relations for display:
     * block/unit/code/date/type via mapDetail().
     */
    public function detailQuery(int $emissionId, int $constructionId, string $referenceMonth, ?string $tipo = null): EloquentBuilder
    {
        $normalized = Negotiation::normalizeReferenceMonth($referenceMonth) ?? $referenceMonth;
        $monthStart = CarbonImmutable::parse($normalized)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $query = Contract::query()
            ->forEmission($emissionId)
            ->where('construction_id', $constructionId)
            ->with(['construction.emission', 'constructionUnit', 'clients'])
            ->orderBy($tipo === 'Distrato' ? 'cancellation_date' : 'sale_date')
            ->orderBy('id');

        if ($tipo === 'Venda') {
            $query->whereDate('sale_date', '>=', $monthStart->toDateString())
                ->whereDate('sale_date', '<=', $monthEnd->toDateString());
        } elseif ($tipo === 'Distrato') {
            $query->whereDate('cancellation_date', '>=', $monthStart->toDateString())
                ->whereDate('cancellation_date', '<=', $monthEnd->toDateString());
        } else {
            // Both types: sale OR cancellation inside month
            $query->where(function (EloquentBuilder $q) use ($monthStart, $monthEnd): void {
                $q->where(function (EloquentBuilder $qq) use ($monthStart, $monthEnd): void {
                    $qq->whereDate('sale_date', '>=', $monthStart->toDateString())
                        ->whereDate('sale_date', '<=', $monthEnd->toDateString());
                })->orWhere(function (EloquentBuilder $qq) use ($monthStart, $monthEnd): void {
                    $qq->whereDate('cancellation_date', '>=', $monthStart->toDateString())
                        ->whereDate('cancellation_date', '<=', $monthEnd->toDateString());
                });
            });
        }

        return $query;
    }

    /**
     * Return mapped detail events (block/unit/code/date/type) for infolist drill-down.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detailEvents(int $emissionId, int $constructionId, string $referenceMonth, ?string $tipo = null): array
    {
        $contracts = $this->detailQuery($emissionId, $constructionId, $referenceMonth, $tipo)->get();

        $normalized = Negotiation::normalizeReferenceMonth($referenceMonth) ?? $referenceMonth;
        $monthStart = CarbonImmutable::parse($normalized)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $events = [];

        foreach ($contracts as $contract) {
            $unit = $contract->constructionUnit;
            $construction = $contract->construction ?? $unit?->construction;
            $block = $unit?->block ?? '—';
            $unitNumber = $unit?->unit ?? '—';
            $display = $unit ? sprintf('Bloco %s — Unidade %s', $block, $unitNumber) : '—';

            // If tipo is null and contract has both events in same month, emit two rows (Venda + Distrato)
            $isSaleInMonth = $contract->sale_date && $contract->sale_date->betweenIncluded($monthStart, $monthEnd);
            $isCancelInMonth = $contract->cancellation_date && $contract->cancellation_date->betweenIncluded($monthStart, $monthEnd);

            if ($tipo === 'Venda' && $isSaleInMonth) {
                $events[] = $this->mapEvent($contract, 'Venda', $contract->sale_date, $construction, $block, $unitNumber, $display);
            } elseif ($tipo === 'Distrato' && $isCancelInMonth) {
                $events[] = $this->mapEvent($contract, 'Distrato', $contract->cancellation_date, $construction, $block, $unitNumber, $display);
            } elseif ($tipo === null) {
                if ($isSaleInMonth) {
                    $events[] = $this->mapEvent($contract, 'Venda', $contract->sale_date, $construction, $block, $unitNumber, $display);
                }
                if ($isCancelInMonth) {
                    $events[] = $this->mapEvent($contract, 'Distrato', $contract->cancellation_date, $construction, $block, $unitNumber, $display);
                }
            }
        }

        // Sort by date then code for deterministic display
        usort($events, fn (array $a, array $b): int => $a['date'] <=> $b['date'] ?: strcmp((string) $a['code'], (string) $b['code']));

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEvent(Contract $contract, string $type, mixed $date, mixed $construction, string $block, string $unitNumber, string $display): array
    {
        return [
            'type' => $type,
            'code' => (string) $contract->code,
            'development' => $construction?->development_name ?? '—',
            'block' => $block,
            'unit' => $unitNumber,
            'date' => $date,
            'date_formatted' => $date?->format('d/m/Y') ?? '—',
            'display' => $display,
            'contract_id' => $contract->id,
        ];
    }

    private function monthTruncateExpression(string $column): string
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: strftime('%Y-%m-01', sale_date)
            return "strftime('%Y-%m-01', {$column})";
        }

        // MySQL / Postgres compatible: DATE_FORMAT or TO_CHAR truncated to month
        // For pgsql fallback: use DATE_TRUNC; but DATE_FORMAT works for MySQL which is production.
        // Use generic approach: DATE_FORMAT for mysql, otherwise TO_CHAR
        if ($driver === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM-DD')";
        }

        return "DATE_FORMAT({$column}, '%Y-%m-01')";
    }

    private function normalizeFilterValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        if (is_array($value) && count($value) === 1 && isset($value[0])) {
            return $value[0];
        }

        return $value;
    }
}
