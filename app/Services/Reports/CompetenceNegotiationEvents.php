<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\SalesBoardPriorPositionResolver;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * As negociações de uma competência para o relatório mensal: os movimentos
 * congelados onde a competência foi publicada pelo Quadro, os contratos onde
 * não foi.
 *
 * Por empreendimento:
 *
 * - com ciclo publicado na competência, vale a publicação vigente (a original
 *   ou a da última retificação): vendas, distratos e quitações congelados. Os
 *   fatos de competências anteriores (extemporâneos e de competência sem
 *   posição) saem rotulados com a data e a competência do fato; a revisão de
 *   venda publicada sai à parte, fora da contagem -- é a mesma venda de antes.
 *   O PDF de uma competência publicada deixa de mudar a cada download, e fecha
 *   mês a mês com a seção de unidades, que já lê a posição publicada;
 * - sem publicação, lê os contratos ({@see ContractNegotiationEvents}), sem os
 *   contratos de permuta. Só a competência que a automação da Emissão cobre é
 *   "prévia sujeita a alteração" -- ela ainda vai ser publicada. A competência
 *   de Quadro legado, ou anterior ao início da automação, nunca terá
 *   publicação: os contratos são a fonte dela, sem rótulo de prévia, como
 *   sempre foram;
 * - a competência cancelada pela Gestão cujos fatos a seguinte publicou
 *   ("absorvida") não lê como prévia o que já foi publicado: aponta a
 *   competência que publicou os fatos dela.
 *
 * Um fato conta uma vez só, na competência em que foi publicado. A leitura dos
 * contratos de uma competência sem publicação desconta o que já virou
 * movimento numa publicação vigente do empreendimento -- a venda e o distrato
 * de competência cancelada absorvidos pela seguinte, o fato atrasado de mês
 * sem ciclo publicado como extemporâneo, a venda com a data movida depois de
 * publicada. O que ainda não foi congelado em publicação nenhuma continua na
 * leitura dos contratos, também uma vez só.
 *
 * Consultas em lote: as obras da emissão, os ciclos publicados com a versão
 * vigente, os movimentos dessas versões, os contratos das obras sem
 * publicação e, deles, os que já são movimento publicado.
 */
class CompetenceNegotiationEvents
{
    public const SOURCE_PUBLISHED = 'publicada';

    public const SOURCE_PREVIEW = 'previa';

    /**
     * Competência sem ciclo mensal (Quadro legado, ou anterior ao início da
     * automação): os contratos são a fonte, sem rótulo de prévia.
     */
    public const SOURCE_CONTRACTS = 'contratos';

    /**
     * Competência cancelada pela Gestão cujos fatos uma competência seguinte
     * publicou.
     */
    public const SOURCE_ABSORBED = 'absorvida';

    /**
     * A situação das linhas lidas dos contratos numa competência sem ciclo
     * mensal.
     */
    public const SITUATION_CONTRACTS = 'Lida dos contratos';

    public function __construct(
        private readonly ContractNegotiationEvents $contractEvents,
    ) {}

    /**
     * @return array{sales: Collection<int, array<string, mixed>>, late_sales: Collection<int, array<string, mixed>>, cancellations: Collection<int, array<string, mixed>>, late_cancellations: Collection<int, array<string, mixed>>, settlements: Collection<int, array<string, mixed>>, revisions: Collection<int, array<string, mixed>>, sources: array<string, string>, absorbed: array<string, string>}
     */
    public function forMonth(Emission $emission, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $constructions = $this->constructionsOf($emission);
        $published = $this->publishedBaselines(array_keys($constructions), $monthStart, $monthStart);
        $publishedHere = $published[$monthStart->format('Y-m')] ?? [];

        $movements = $this->movementsOf(array_values($publishedHere));
        $unpublished = array_values(array_diff(array_keys($constructions), array_keys($publishedHere)));

        $live = $this->contractEvents->forMonth($emission, $monthStart, $monthEnd, $unpublished);
        $frozen = $this->frozenFacts(collect([...$live['sales'], ...$live['cancellations']])->pluck('contract_id')->all());

        $rows = $movements->map(fn (SalesBoardCycleMovement $movement): array => $this->movementRow(
            $movement,
            $constructions[(int) $movement->baseline->cycle->construction_id] ?? '—',
        ));

        $covered = $emission->automationCovers($monthStart);
        $absorbedBy = $covered ? $this->absorbingCompetences($unpublished, $monthStart) : [];

        $sources = [];
        $absorbed = [];

        foreach ($constructions as $constructionId => $name) {
            $sources[$name] = match (true) {
                isset($publishedHere[$constructionId]) => self::SOURCE_PUBLISHED,
                ! $covered => self::SOURCE_CONTRACTS,
                isset($absorbedBy[$constructionId]) => self::SOURCE_ABSORBED,
                default => self::SOURCE_PREVIEW,
            };

            if (isset($absorbedBy[$constructionId])) {
                $absorbed[$name] = $absorbedBy[$constructionId]->format('m/Y');
            }
        }

        /**
         * O que a leitura dos contratos traz e já é movimento de uma publicação
         * vigente fica de fora: conta onde foi publicado. Fora da cobertura da
         * automação a linha é dos contratos, sem rótulo de prévia.
         */
        $mark = static fn (Collection $events, string $type): Collection => $events
            ->reject(fn (array $event): bool => isset($frozen[$type.':'.(int) $event['contract_id']]))
            ->map(fn (array $event): array => [
                ...$event,
                'competence' => $monthStart->format('m/Y'),
                'situation' => $covered ? 'Prévia' : self::SITUATION_CONTRACTS,
                'late' => false,
            ]);

        $byDate = static fn (Collection $events): Collection => $events->sortBy([
            fn (array $a, array $b): int => (string) ($a['date_sort'] ?? $a['date']?->format('Y-m-d') ?? '9999') <=> (string) ($b['date_sort'] ?? $b['date']?->format('Y-m-d') ?? '9999'),
            fn (array $a, array $b): int => (int) $a['contract_id'] <=> (int) $b['contract_id'],
        ])->values();

        return [
            'sales' => $byDate($rows->filter(fn (array $row): bool => ($row['kind'] === 'sale') && ! $row['late'])->merge($mark($live['sales'], 'sale'))),
            'late_sales' => $byDate($rows->filter(fn (array $row): bool => ($row['kind'] === 'sale') && $row['late'])),
            'cancellations' => $byDate($rows->filter(fn (array $row): bool => ($row['kind'] === 'cancellation') && ! $row['late'])->merge($mark($live['cancellations'], 'cancellation'))),
            'late_cancellations' => $byDate($rows->filter(fn (array $row): bool => ($row['kind'] === 'cancellation') && $row['late'])),
            'settlements' => $byDate($rows->filter(fn (array $row): bool => $row['kind'] === 'settlement')),
            'revisions' => $byDate($rows->filter(fn (array $row): bool => $row['kind'] === 'revision')),
            'sources' => $sources,
            'absorbed' => $absorbed,
        ];
    }

    /**
     * Contagem por competência: a competência publicada conta os movimentos
     * congelados da publicação vigente -- inclusive os fatos de competências
     * anteriores publicados nela --, a não publicada conta os contratos, menos
     * os que já são movimento de uma publicação vigente. Cada fato conta uma
     * vez: a venda da competência cancelada que a seguinte absorveu conta na
     * seguinte, e não de novo como prévia do mês cancelado.
     *
     * @return list<array{competencia: string, sales: int, cancellations: int, net: int, late: int, has: bool}>
     */
    public function historyCounts(Emission $emission, CarbonImmutable $monthEnd, int $limit = 6): array
    {
        $constructions = array_keys($this->constructionsOf($emission));
        $start = $monthEnd->copy()->subMonthsNoOverflow($limit - 1)->startOfMonth();
        $published = $this->publishedBaselines($constructions, $start, $monthEnd->copy()->startOfMonth());

        $baselineIds = collect($published)->flatMap(fn (array $byConstruction): array => array_values($byConstruction))->all();
        $counts = $this->movementCounts($baselineIds);
        $liveEvents = $this->contractEvents->historyEvents($emission, $start, $monthEnd->copy()->endOfMonth(), $constructions);
        $frozen = $this->frozenFacts(array_column($liveEvents, 'contract_id'));

        $rows = [];

        for ($cursor = $start; $cursor->lessThanOrEqualTo($monthEnd); $cursor = $cursor->addMonthNoOverflow()) {
            $ym = $cursor->format('Y-m');
            $publishedHere = $published[$ym] ?? [];
            $sales = 0;
            $cancellations = 0;
            $late = 0;

            foreach ($publishedHere as $baselineId) {
                $sales += $counts[$baselineId]['sales'] ?? 0;
                $cancellations += $counts[$baselineId]['cancellations'] ?? 0;
                $late += $counts[$baselineId]['late'] ?? 0;
            }

            foreach ($liveEvents as $event) {
                if (($event['month'] !== $ym)
                    || isset($publishedHere[$event['construction_id']])
                    || isset($frozen[$event['type'].':'.$event['contract_id']])) {
                    continue;
                }

                $event['type'] === 'sale' ? $sales++ : $cancellations++;
            }

            $rows[] = [
                'competencia' => $cursor->format('m/Y'),
                'sales' => $sales,
                'cancellations' => $cancellations,
                'net' => $sales - $cancellations,
                'late' => $late,
                'has' => ($sales > 0) || ($cancellations > 0),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string> obra => nome
     */
    private function constructionsOf(Emission $emission): array
    {
        return Construction::query()
            ->where('emission_id', $emission->getKey())
            ->orderBy('id')
            ->pluck('development_name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * A versão da publicação vigente de cada obra em cada competência
     * publicada da janela. Uma consulta.
     *
     * @param  list<int>  $constructionIds
     * @return array<string, array<int, int>> `Y-m` => obra => versão publicada
     */
    private function publishedBaselines(array $constructionIds, CarbonImmutable $from, CarbonImmutable $through): array
    {
        if ($constructionIds === []) {
            return [];
        }

        $cycles = (new SalesBoardCycle)->getTable();
        $publications = (new SalesBoardPublication)->getTable();

        $rows = DB::table($cycles.' as cycles')
            ->whereIn('cycles.construction_id', $constructionIds)
            ->whereBetween('cycles.reference_month', [$from->startOfMonth()->toDateString(), InclusiveDateBound::upperBound($through->endOfMonth())])
            ->select(['cycles.construction_id', 'cycles.reference_month'])
            ->selectSub(
                fn (QueryBuilder $query) => $query->from($publications.' as publications')
                    ->select('publications.sales_board_cycle_baseline_id')
                    ->whereColumn('publications.sales_board_cycle_id', 'cycles.id')
                    ->orderByDesc('publications.sequence_number')
                    ->limit(1),
                'published_baseline_id',
            )
            ->get();

        $published = [];

        foreach ($rows as $row) {
            if ($row->published_baseline_id === null) {
                continue;
            }

            $published[substr((string) $row->reference_month, 0, 7)][(int) $row->construction_id] = (int) $row->published_baseline_id;
        }

        return $published;
    }

    /**
     * As vendas e os distratos dos contratos que já são movimento de uma
     * publicação vigente do Quadro -- de qualquer competência, do mês ou de
     * competência anterior --, como `sale:{contrato}` e `cancellation:{contrato}`.
     * A revisão de venda não entra: é a mesma venda de antes.
     *
     * Uma consulta, só sobre os contratos que a leitura ao vivo trouxe: a
     * publicação que congelou o fato pode ser de qualquer mês, inclusive
     * posterior ao do relatório.
     *
     * @param  list<int|string|null>  $contractIds
     * @return array<string, true>
     */
    private function frozenFacts(array $contractIds): array
    {
        $contractIds = array_values(array_unique(array_filter(array_map('intval', $contractIds))));

        if ($contractIds === []) {
            return [];
        }

        $publications = (new SalesBoardPublication)->getTable();
        $movements = (new SalesBoardCycleMovement)->getTable();
        $facts = [];

        DB::table($movements.' as movements')
            ->join($publications.' as publications', 'publications.sales_board_cycle_baseline_id', '=', 'movements.sales_board_cycle_baseline_id')
            ->where('publications.sequence_number', '=', fn (QueryBuilder $latest): QueryBuilder => $latest
                ->from($publications.' as latest')
                ->selectRaw('max(latest.sequence_number)')
                ->whereColumn('latest.sales_board_cycle_id', 'publications.sales_board_cycle_id'))
            ->whereIn('movements.contract_id', $contractIds)
            ->whereIn('movements.movement_type', [SalesBoardMovementType::Sale->value, SalesBoardMovementType::Cancellation->value])
            ->where(fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull('movements.timing')
                ->orWhere('movements.timing', '!=', SalesBoardMovementTiming::SaleRevision->value))
            ->distinct()
            ->get(['movements.movement_type', 'movements.contract_id'])
            ->each(function (object $row) use (&$facts): void {
                $type = (string) $row->movement_type === SalesBoardMovementType::Sale->value ? 'sale' : 'cancellation';
                $facts[$type.':'.(int) $row->contract_id] = true;
            });

        return $facts;
    }

    /**
     * Das obras sem publicação na competência, as que tiveram o ciclo dela
     * cancelado pela Gestão e absorvido por uma competência seguinte publicada,
     * com a competência que a absorveu.
     *
     * A competência que absorve é a primeira não cancelada depois do mês, com
     * ciclo em todos os meses do caminho -- a mesma cadeia da âncora
     * ({@see SalesBoardPriorPositionResolver}), andada para a frente. Mês sem
     * ciclo interrompe: a seguinte não absorve o que fica antes dele. Uma
     * consulta, limitada ao alcance da cadeia.
     *
     * @param  list<int>  $constructionIds
     * @return array<int, CarbonImmutable> obra => competência que absorveu
     */
    private function absorbingCompetences(array $constructionIds, CarbonImmutable $monthStart): array
    {
        if ($constructionIds === []) {
            return [];
        }

        $cycles = (new SalesBoardCycle)->getTable();
        $publications = (new SalesBoardPublication)->getTable();
        $through = $monthStart->addMonthsNoOverflow(SalesBoardPriorPositionResolver::MAXIMUM_MONTHS_BACK)->endOfMonth();

        $byConstruction = [];

        DB::table($cycles.' as cycles')
            ->whereIn('cycles.construction_id', $constructionIds)
            ->whereBetween('cycles.reference_month', [$monthStart->toDateString(), InclusiveDateBound::upperBound($through)])
            ->select(['cycles.construction_id', 'cycles.reference_month', 'cycles.status'])
            ->selectSub(
                fn (QueryBuilder $query): QueryBuilder => $query->from($publications.' as publications')
                    ->selectRaw('count(*)')
                    ->whereColumn('publications.sales_board_cycle_id', 'cycles.id'),
                'publications_count',
            )
            ->get()
            ->each(function (object $row) use (&$byConstruction): void {
                $byConstruction[(int) $row->construction_id][substr((string) $row->reference_month, 0, 7)] = $row;
            });

        $absorbing = [];

        foreach ($byConstruction as $constructionId => $cyclesByMonth) {
            $own = $cyclesByMonth[$monthStart->format('Y-m')] ?? null;

            if (($own === null) || ((string) $own->status !== SalesBoardCycleStatus::Cancelled->value)) {
                continue;
            }

            for ($ahead = 1; $ahead <= SalesBoardPriorPositionResolver::MAXIMUM_MONTHS_BACK; $ahead++) {
                $candidate = $monthStart->addMonthsNoOverflow($ahead);
                $row = $cyclesByMonth[$candidate->format('Y-m')] ?? null;

                if ($row === null) {
                    break;
                }

                if ((string) $row->status === SalesBoardCycleStatus::Cancelled->value) {
                    continue;
                }

                if ((int) $row->publications_count > 0) {
                    $absorbing[(int) $constructionId] = $candidate;
                }

                break;
            }
        }

        return $absorbing;
    }

    /**
     * Os movimentos congelados das versões publicadas, com o ciclo de cada uma.
     *
     * @param  list<int>  $baselineIds
     * @return Collection<int, SalesBoardCycleMovement>
     */
    private function movementsOf(array $baselineIds): Collection
    {
        if ($baselineIds === []) {
            return collect();
        }

        return SalesBoardCycleMovement::query()
            ->whereIn('sales_board_cycle_baseline_id', $baselineIds)
            ->with('baseline:id,sales_board_cycle_id', 'baseline.cycle:id,construction_id')
            ->orderBy('event_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Vendas (sem as revisões), distratos e fatos de competências anteriores de
     * cada versão publicada, agrupados no banco. Uma consulta.
     *
     * @param  list<int>  $baselineIds
     * @return array<int, array{sales: int, cancellations: int, late: int}>
     */
    private function movementCounts(array $baselineIds): array
    {
        if ($baselineIds === []) {
            return [];
        }

        $counts = [];

        SalesBoardCycleMovement::query()
            ->toBase()
            ->whereIn('sales_board_cycle_baseline_id', $baselineIds)
            ->groupBy('sales_board_cycle_baseline_id', 'movement_type', 'timing')
            ->select(['sales_board_cycle_baseline_id', 'movement_type', 'timing'])
            ->selectRaw('COUNT(*) as aggregate')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $baselineId = (int) $row->sales_board_cycle_baseline_id;
                $counts[$baselineId] ??= ['sales' => 0, 'cancellations' => 0, 'late' => 0];
                $timing = $row->timing === null ? null : SalesBoardMovementTiming::tryFrom((string) $row->timing);
                $total = (int) $row->aggregate;

                if ($timing === SalesBoardMovementTiming::SaleRevision) {
                    return;
                }

                match ((string) $row->movement_type) {
                    SalesBoardMovementType::Sale->value => $counts[$baselineId]['sales'] += $total,
                    SalesBoardMovementType::Cancellation->value => $counts[$baselineId]['cancellations'] += $total,
                    default => null,
                };

                if (($timing !== null) && ((string) $row->movement_type !== SalesBoardMovementType::Settlement->value)) {
                    $counts[$baselineId]['late'] += $total;
                }
            });

        return $counts;
    }

    /**
     * Um movimento congelado na forma das linhas do relatório, com a
     * competência do fato e a situação -- publicada, extemporânea ou de
     * competência sem posição.
     *
     * @return array<string, mixed>
     */
    private function movementRow(SalesBoardCycleMovement $movement, string $constructionName): array
    {
        $timing = $movement->timing;
        $type = $movement->movement_type;
        $factDate = $movement->event_date;
        $factCompetence = match ($type) {
            SalesBoardMovementType::Sale => $movement->sale_date?->format('m/Y'),
            SalesBoardMovementType::Cancellation => $movement->cancellation_date?->format('m/Y'),
            SalesBoardMovementType::Settlement => null,
        };

        $kind = match (true) {
            $timing === SalesBoardMovementTiming::SaleRevision => 'revision',
            $type === SalesBoardMovementType::Sale => 'sale',
            $type === SalesBoardMovementType::Cancellation => 'cancellation',
            default => 'settlement',
        };

        return [
            'type' => $kind === 'revision' ? 'Revisão de venda' : $type->label(),
            'kind' => $kind,
            'code' => (string) $movement->contract_code,
            'development' => $constructionName,
            'block' => $movement->block ?? '—',
            'unit' => $movement->unit ?? '—',
            'date' => $factDate,
            'date_sort' => $factDate?->format('Y-m-d'),
            'date_formatted' => $factDate?->format('d/m/Y') ?? '—',
            'display' => sprintf('Bloco %s — Unidade %s', $movement->block ?? '—', $movement->unit ?? '—'),
            'contract_id' => (int) $movement->contract_id,
            'construction_id' => (int) $movement->baseline->cycle->construction_id,
            'competence' => $factCompetence ?? '—',
            'late' => ($timing !== null) && ($kind !== 'revision'),
            'situation' => match ($timing) {
                null => 'Publicada',
                SalesBoardMovementTiming::Extemporaneous => sprintf('Extemporânea (%s)', $factCompetence ?? 'competência anterior'),
                SalesBoardMovementTiming::WithoutPosition => sprintf('De competência sem posição (%s)', $factCompetence ?? '—'),
                SalesBoardMovementTiming::SaleRevision => 'Revisão de venda publicada',
            },
        ];
    }
}
