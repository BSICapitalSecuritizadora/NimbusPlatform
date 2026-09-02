<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Emission;
use App\Models\Negotiation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Global Negociações projection: UNION of legacy manual rows and contract-derived aggregates.
 *
 * Each emission retains its single source of truth, but the listing
 * must display BOTH kinds simultaneously without requiring a filter.
 *
 * - contracts source → derived via ContractNegotiationAggregates (GROUP BY emission,construction,YM)
 * - legacy source    → direct Negotiation rows
 *
 * No synthetic persistence into negotiations table.
 */
class NegotiationGlobalProjection
{
    public function __construct(private ContractNegotiationAggregates $aggregates) {}

    /**
     * Whether the global listing should use the projection (at least one contracts emission exists).
     */
    public function shouldUseProjection(): bool
    {
        return Emission::query()
            ->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_CONTRACTS)
            ->exists();
    }

    /**
     * Whether any negotiation data exists across BOTH sources (for emptyState).
     */
    public function hasAnyData(): bool
    {
        if (Negotiation::query()->exists()) {
            return true;
        }

        // Any contract aggregate exists?
        return $this->aggregates->aggregatedQuery([])->exists();
    }

    /**
     * Eloquent builder that unions legacy + contract aggregates, suitable for Filament table.
     *
     * Filters: emission_id, construction_id, reference_month, tipo (Venda|Distrato), search.
     * The builder hydrates Negotiation models (synthetic ids for contract rows) so
     * existing columns (emission.name, construction.development_name, etc.) keep working
     * via eager loads.
     */
    public function eloquentQuery(array $filters = []): EloquentBuilder
    {
        $emissionId = $this->normalize($filters['emission_id'] ?? null);
        $constructionId = $this->normalize($filters['construction_id'] ?? null);
        $referenceMonth = $this->normalize($filters['reference_month'] ?? null);
        $tipo = $this->normalize($filters['tipo'] ?? null);

        if (filled($referenceMonth)) {
            $referenceMonth = Negotiation::normalizeReferenceMonth($referenceMonth) ?? $referenceMonth;
        }

        $union = $this->buildUnionQuery();

        // Outer Eloquent builder from subquery so Filament can paginate/sort
        // Use 'negotiations' alias so Filament's default order by negotiations.id resolves
        $query = Negotiation::query()->fromSub($union, 'negotiations')
            ->select('negotiations.*');

        if (filled($emissionId)) {
            $query->where('negotiations.emission_id', $emissionId);
        }
        if (filled($constructionId)) {
            $query->where('negotiations.construction_id', $constructionId);
        }
        if (filled($referenceMonth)) {
            $query->where('negotiations.reference_month', $referenceMonth);
        }
        if ($tipo === 'Venda') {
            $query->where('negotiations.sales', '>', 0);
        } elseif ($tipo === 'Distrato') {
            $query->where('negotiations.cancellations', '>', 0);
        }

        // Default ordering: Competência DESC, Emissão, Empreendimento
        // Handled by table defaultSort, but ensure deterministic
        return $query->with(['emission', 'construction']);
    }

    /**
     * Raw UNION query (QueryBuilder) for counting / testing without Eloquent wrapping.
     */
    public function unionQuery(): QueryBuilder
    {
        return $this->buildUnionQuery();
    }

    private function buildUnionQuery(): QueryBuilder
    {
        $driver = DB::getDriverName();

        // Synthetic id for contract rows: CRC32 of composite key → large int unlikely to collide with real Negotiation ids
        // Use ABS(CRC32(...)) for MySQL, for SQLite use (emission_id*10000000 + construction_id*1000 + YYYYMM)
        $syntheticIdExpr = match ($driver) {
            'sqlite' => 'CAST((emission_id * 10000000 + construction_id * 10000 + CAST(substr(reference_month,1,4) AS INTEGER)*100 + CAST(substr(reference_month,6,2) AS INTEGER)) AS INTEGER) + 1000000000',
            default => "ABS(CRC32(CONCAT(emission_id, '-', construction_id, '-', reference_month))) + 1000000",
        };

        // Contract-derived aggregates subquery
        $contractAggregates = $this->aggregates->aggregatedQuery([])
            ->selectRaw("{$syntheticIdExpr} as id")
            ->selectRaw("'contracts' as source");
        // aggregatedQuery already selects emission_id, construction_id, reference_month, sales, cancellations, emission_name, development_name
        // We need to ensure those columns are present with correct aliases for union

        // Legacy rows subquery — must match same column list: id, emission_id, construction_id, reference_month, sales, cancellations, emission_name, development_name, source
        // negotiations has no soft deletes
        $legacy = DB::table('negotiations as n')
            ->join('constructions as c', 'c.id', '=', 'n.construction_id')
            ->join('emissions as e', 'e.id', '=', 'n.emission_id')
            ->where(function ($q) {
                $q->where('e.negotiations_source', Emission::NEGOTIATIONS_SOURCE_LEGACY)
                    ->orWhereNull('e.negotiations_source');
            })
            ->select([
                'n.id',
                'n.emission_id',
                'n.construction_id',
                'n.reference_month',
                'n.sales',
                'n.cancellations',
            ])
            ->selectRaw('e.name as emission_name')
            ->selectRaw('c.development_name as development_name')
            ->selectRaw("'legacy' as source");

        // For contract side, aggregatedQuery currently selects emission_name/development_name via MAX, but we need to adapt
        // Rebuild contract side to match legacy column order explicitly
        // Instead of reusing aggregatedQuery directly, we build a compatible contract subquery here
        $contractSub = $this->buildContractSubqueryForUnion($syntheticIdExpr);

        $union = $contractSub->unionAll($legacy);

        // Wrap union as subquery for outer builder
        return DB::query()->fromSub($union, 'u')->select('u.*');
    }

    private function buildContractSubqueryForUnion(string $syntheticIdExpr): QueryBuilder
    {
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

        $aggregated = DB::query()->fromSub($union, 'q')
            ->join('constructions as c', 'c.id', '=', 'q.construction_id')
            ->join('emissions as e', 'e.id', '=', 'c.emission_id')
            ->where('e.negotiations_source', Emission::NEGOTIATIONS_SOURCE_CONTRACTS)
            ->selectRaw("{$syntheticIdExpr} as id")
            ->selectRaw('c.emission_id as emission_id')
            ->selectRaw('q.construction_id as construction_id')
            ->selectRaw('q.reference_month as reference_month')
            ->selectRaw('SUM(q.vendas) as sales')
            ->selectRaw('SUM(q.distratos) as cancellations')
            ->selectRaw('MAX(e.name) as emission_name')
            ->selectRaw('MAX(c.development_name) as development_name')
            ->selectRaw("'contracts' as source")
            ->groupBy('c.emission_id', 'q.construction_id', 'q.reference_month');

        return $aggregated;
    }

    private function monthTruncateExpression(string $column): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return "strftime('%Y-%m-01', {$column})";
        }
        if ($driver === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM-DD')";
        }

        return "DATE_FORMAT({$column}, '%Y-%m-01')";
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }
        if (is_array($value) && array_key_exists('values', $value) && is_array($value['values'])) {
            return $value['values'][0] ?? null;
        }

        return $value;
    }
}
