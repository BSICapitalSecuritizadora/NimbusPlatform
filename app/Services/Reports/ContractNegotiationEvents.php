<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\Emission;
use App\Support\SalesBoards\ExchangeContractRecognizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Derives monthly negotiation events from contracts.
 *
 * Single source of truth: contracts.sale_date and contracts.cancellation_date.
 * Scoped strictly to the emission (via construction -> emission), and -- when
 * the caller asks -- to some of its constructions: the report reads contracts
 * only where the competence has no published Sales Board position
 * ({@see CompetenceNegotiationEvents}).
 *
 * O contrato de permuta não é venda nem distrato: a mesma regra do Quadro
 * ({@see ExchangeContractRecognizer}), com as permutas das unidades carregadas
 * numa consulta.
 */
class ContractNegotiationEvents
{
    /**
     * @param  list<int>|null  $constructionIds  só estas obras da emissão; `null` são todas
     * @return array{sales: Collection<int, array<string, mixed>>, cancellations: Collection<int, array<string, mixed>>}
     */
    public function forMonth(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, ?array $constructionIds = null): array
    {
        if ($constructionIds === []) {
            return ['sales' => collect(), 'cancellations' => collect()];
        }

        $sales = $this->scoped($emission, $constructionIds)
            ->whereDate('sale_date', '>=', $monthStart->toDateString())
            ->whereDate('sale_date', '<=', $monthEnd->toDateString())
            ->with(['constructionUnit.construction', 'construction'])
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get();

        $cancellations = $this->scoped($emission, $constructionIds)
            ->whereDate('cancellation_date', '>=', $monthStart->toDateString())
            ->whereDate('cancellation_date', '<=', $monthEnd->toDateString())
            ->with(['constructionUnit.construction', 'construction'])
            ->orderBy('cancellation_date')
            ->orderBy('id')
            ->get();

        $exchanges = $this->exchangesOf($sales->merge($cancellations));

        return [
            'sales' => $sales
                ->reject(fn (Contract $contract): bool => ExchangeContractRecognizer::isExchangeSaleOnRecord(
                    $contract,
                    $exchanges->get((int) $contract->construction_unit_id, collect()),
                ))
                ->map(fn (Contract $contract): array => $this->mapSale($contract))
                ->values(),
            'cancellations' => $cancellations
                ->reject(fn (Contract $contract): bool => ExchangeContractRecognizer::isExchangeCancellation(
                    $contract,
                    $exchanges->get((int) $contract->construction_unit_id, collect()),
                ))
                ->map(fn (Contract $contract): array => $this->mapCancellation($contract))
                ->values(),
        ];
    }

    /**
     * @param  list<int>|null  $constructionIds
     * @return Builder<Contract>
     */
    private function scoped(Emission $emission, ?array $constructionIds): Builder
    {
        return Contract::query()
            ->forEmission($emission->id)
            ->when($constructionIds !== null, fn (Builder $query): Builder => $query->whereIn('construction_id', $constructionIds));
    }

    /**
     * As permutas das unidades dos contratos, agrupadas por unidade, numa
     * consulta.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return Collection<int, Collection<int, ConstructionUnitExchange>>
     */
    private function exchangesOf(Collection $contracts): Collection
    {
        $unitIds = $contracts
            ->map(fn (Contract $contract): int => (int) $contract->construction_unit_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($unitIds === []) {
            return collect();
        }

        return ConstructionUnitExchange::query()
            ->whereIn('construction_unit_id', $unitIds)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ConstructionUnitExchange $exchange): int => (int) $exchange->construction_unit_id);
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
            'construction_id' => $construction?->getKey(),
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
            'construction_id' => $construction?->getKey(),
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
     * Os contratos são devolvidos com a obra e o mês, para quem precisa contar
     * só as obras e os meses sem posição publicada; o contrato de permuta fica
     * de fora, como na competência.
     *
     * @param  list<int>|null  $constructionIds
     * @return Collection<int, array{competencia: string, sales: int, cancellations: int, net: int}>
     */
    public function historyCounts(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6, ?array $constructionIds = null): Collection
    {
        $start = $monthEnd->copy()->subMonthsNoOverflow($limit - 1)->startOfMonth();
        $events = $this->historyEvents($emission, $start, $monthEnd->copy()->endOfMonth(), $constructionIds);

        $months = collect();
        $cursor = $start->copy();
        while ($cursor->lte($monthEnd)) {
            $months->push($cursor->format('Y-m'));
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months->map(function (string $ym) use ($events): array {
            $sales = count(array_filter($events, fn (array $event): bool => ($event['type'] === 'sale') && ($event['month'] === $ym)));
            $cancellations = count(array_filter($events, fn (array $event): bool => ($event['type'] === 'cancellation') && ($event['month'] === $ym)));

            return [
                'competencia' => CarbonImmutable::parse($ym.'-01')->format('m/Y'),
                'sales' => $sales,
                'cancellations' => $cancellations,
                'net' => $sales - $cancellations,
            ];
        })->values();
    }

    /**
     * As vendas e os distratos da janela, um por evento, com o contrato, a obra
     * e o mês (`Y-m`) -- sem os contratos de permuta. Duas consultas: os
     * contratos e as permutas das unidades deles.
     *
     * O contrato vai junto para quem precisa descontar o fato que já foi
     * publicado como movimento de outra competência ({@see CompetenceNegotiationEvents}).
     *
     * @param  list<int>|null  $constructionIds
     * @return list<array{type: string, contract_id: int, construction_id: int, month: string}>
     */
    public function historyEvents(Emission $emission, CarbonImmutable $start, CarbonImmutable $end, ?array $constructionIds = null): array
    {
        if ($constructionIds === []) {
            return [];
        }

        $contracts = $this->scoped($emission, $constructionIds)
            ->where(function (Builder $query) use ($start, $end): void {
                $query->where(function (Builder $q) use ($start, $end): void {
                    $q->whereDate('sale_date', '>=', $start->toDateString())
                        ->whereDate('sale_date', '<=', $end->toDateString());
                })->orWhere(function (Builder $q) use ($start, $end): void {
                    $q->whereDate('cancellation_date', '>=', $start->toDateString())
                        ->whereDate('cancellation_date', '<=', $end->toDateString());
                });
            })
            ->get(['id', 'construction_id', 'construction_unit_id', 'status', 'sale_date', 'cancellation_date']);

        $exchanges = $this->exchangesOf($contracts);
        $startMonth = $start->format('Y-m');
        $endMonth = $end->format('Y-m');
        $events = [];

        foreach ($contracts as $contract) {
            $exchangesOfUnit = $exchanges->get((int) $contract->construction_unit_id, collect());
            $saleMonth = $contract->sale_date?->format('Y-m');
            $cancellationMonth = $contract->cancellation_date?->format('Y-m');

            if (($saleMonth !== null) && ($saleMonth >= $startMonth) && ($saleMonth <= $endMonth)
                && ! ExchangeContractRecognizer::isExchangeSaleOnRecord($contract, $exchangesOfUnit)) {
                $events[] = ['type' => 'sale', 'contract_id' => (int) $contract->getKey(), 'construction_id' => (int) $contract->construction_id, 'month' => $saleMonth];
            }

            if (($cancellationMonth !== null) && ($cancellationMonth >= $startMonth) && ($cancellationMonth <= $endMonth)
                && ! ExchangeContractRecognizer::isExchangeCancellation($contract, $exchangesOfUnit)) {
                $events[] = ['type' => 'cancellation', 'contract_id' => (int) $contract->getKey(), 'construction_id' => (int) $contract->construction_id, 'month' => $cancellationMonth];
            }
        }

        return $events;
    }
}
