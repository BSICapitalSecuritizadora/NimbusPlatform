<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Derives monthly negotiation events from contracts.
 *
 * Single source of truth: contracts.sale_date and contracts.cancellation_date.
 * Scoped strictly to the emission (via construction -> emission).
 */
class ContractNegotiationEvents
{
    /**
     * @return array{sales: Collection<int, array<string, mixed>>, cancellations: Collection<int, array<string, mixed>>}
     */
    public function forMonth(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $sales = Contract::query()
            ->forEmission($emission->id)
            ->whereDate('sale_date', '>=', $monthStart->toDateString())
            ->whereDate('sale_date', '<=', $monthEnd->toDateString())
            ->with(['constructionUnit.construction', 'construction'])
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract): array => $this->mapSale($contract));

        $cancellations = Contract::query()
            ->forEmission($emission->id)
            ->whereDate('cancellation_date', '>=', $monthStart->toDateString())
            ->whereDate('cancellation_date', '<=', $monthEnd->toDateString())
            ->with(['constructionUnit.construction', 'construction'])
            ->orderBy('cancellation_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract): array => $this->mapCancellation($contract));

        return [
            'sales' => $sales,
            'cancellations' => $cancellations,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapSale(Contract $contract): array
    {
        $unit = $contract->constructionUnit;
        $construction = $contract->construction ?? $unit?->construction;

        return [
            'type' => 'Venda',
            'code' => (string) $contract->code,
            'development' => $construction?->development_name ?? '—',
            'block' => $unit?->block ?? '—',
            'unit' => $unit?->unit ?? '—',
            'date' => $contract->sale_date,
            'date_formatted' => $contract->sale_date?->format('d/m/Y') ?? '—',
            'display' => $this->unitDisplay($unit),
            'contract_id' => $contract->id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapCancellation(Contract $contract): array
    {
        $unit = $contract->constructionUnit;
        $construction = $contract->construction ?? $unit?->construction;

        return [
            'type' => 'Distrato',
            'code' => (string) $contract->code,
            'development' => $construction?->development_name ?? '—',
            'block' => $unit?->block ?? '—',
            'unit' => $unit?->unit ?? '—',
            'date' => $contract->cancellation_date,
            'date_formatted' => $contract->cancellation_date?->format('d/m/Y') ?? '—',
            'display' => $this->unitDisplay($unit),
            'contract_id' => $contract->id,
        ];
    }

    private function unitDisplay(mixed $unit): string
    {
        if (! $unit) {
            return '—';
        }

        $block = $unit->block ?? '—';
        $unitNumber = $unit->unit ?? '—';

        // Prefer verbose format "Bloco X — Unidade Y" for clarity, fallback to "X / Y" is handled in view if needed.
        return sprintf('Bloco %s — Unidade %s', $block, $unitNumber);
    }

    /**
     * Build historical series of counts per competence from contracts.
     *
     * @return Collection<int, array{competencia: string, sales: int, cancellations: int, net: int}>
     */
    public function historyCounts(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): Collection
    {
        $start = $monthEnd->copy()->subMonthsNoOverflow($limit - 1)->startOfMonth();
        $end = $monthEnd->copy()->endOfMonth();

        $contracts = Contract::query()
            ->forEmission($emission->id)
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($q) use ($start, $end): void {
                    $q->whereDate('sale_date', '>=', $start->toDateString())
                        ->whereDate('sale_date', '<=', $end->toDateString());
                })->orWhere(function ($q) use ($start, $end): void {
                    $q->whereDate('cancellation_date', '>=', $start->toDateString())
                        ->whereDate('cancellation_date', '<=', $end->toDateString());
                });
            })
            ->get(['sale_date', 'cancellation_date']);

        $months = collect();
        $cursor = $start->copy();
        while ($cursor->lte($monthEnd)) {
            $months->push($cursor->format('Y-m'));
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months->map(function (string $ym) use ($contracts): array {
            $sales = $contracts->filter(fn (Contract $c): bool => $c->sale_date?->format('Y-m') === $ym)->count();
            $cancellations = $contracts->filter(fn (Contract $c): bool => $c->cancellation_date?->format('Y-m') === $ym)->count();

            return [
                'competencia' => CarbonImmutable::parse($ym.'-01')->format('m/Y'),
                'sales' => $sales,
                'cancellations' => $cancellations,
                'net' => $sales - $cancellations,
            ];
        })->values();
    }
}
