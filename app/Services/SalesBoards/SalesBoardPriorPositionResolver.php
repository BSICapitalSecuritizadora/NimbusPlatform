<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardCompetenceChain;
use App\DTOs\SalesBoards\SalesBoardPriorLine;
use App\DTOs\SalesBoards\SalesBoardPriorPosition;
use App\Enums\ContractSettlementState;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardSource;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardPublication;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A âncora da competência M: a posição congelada da competência anterior, contra
 * a qual a derivação de M apura os fatos que chegaram atrasados.
 *
 * A âncora é a competência **imediatamente anterior** do empreendimento, e não a
 * última aprovada. Com M-1 aberta, ancorar na última aprovada faria M-1 e M
 * reivindicarem os mesmos fatos; com a cadeia, cada fato atrasado tem um dono
 * só -- a competência seguinte à posição congelada que não o refletiu.
 *
 * - percorre M-1, M-2… enquanto o ciclo daquele mês existir e estiver
 *   cancelado; o primeiro ciclo não cancelado é a âncora;
 * - a versão usada é a da publicação vigente (maior sequência) quando há
 *   publicação -- estável enquanto a anterior está em retificação --, senão a
 *   versão vigente do ciclo;
 * - mês sem ciclo interrompe a cadeia: sem âncora, sem extemporâneos. Cobre a
 *   primeira competência automatizada, a obra nova e o legado.
 *
 * A cadeia que termina num mês sem ciclo depois de pular competências
 * canceladas -- a primeira competência automatizada cancelada, por exemplo --
 * não tem âncora, mas os meses cancelados dela não ficam sem dono: a
 * competência os absorve, com a janela dos movimentos começando no primeiro
 * dia do mais antigo ({@see SalesBoardCompetenceChain}). Só entram os meses que
 * a automação da Emissão do ciclo cobre: o cancelado que voltou ao registro
 * manual (retorno ao legado, ou início da automação movido para depois dele) é
 * do quadro manual, e a reabertura dele também é recusada pelo mesmo motivo.
 *
 * O portão de ordem da aprovação ({@see SalesBoardPriorCompetenceGate}) anda a
 * mesma cadeia, com o mesmo limite: a âncora que ele exige publicada é a que
 * esta classe devolve.
 *
 * Duas leituras no máximo, constantes no número de obras: os ciclos dos 24
 * meses anteriores com a versão publicada vigente (subconsulta nas
 * publicações) e a cobertura da Emissão de cada um (junção), e, havendo âncora,
 * as linhas congeladas delas. Só lê o que foi congelado; nenhuma consulta toca
 * a fonte viva.
 */
class SalesBoardPriorPositionResolver
{
    /**
     * Até onde a cadeia de competências canceladas é seguida. Mais de dois anos
     * de competências canceladas seguidas não é cadeia, é obra fora da
     * automação.
     */
    public const MAXIMUM_MONTHS_BACK = 24;

    /**
     * @param  iterable<int|string>  $constructionIds
     * @return array<int, SalesBoardPriorPosition> indexado por `construction_id`, só as obras com âncora
     */
    public function forConstructions(iterable $constructionIds, CarbonInterface $referenceMonth): array
    {
        return $this->chainsFor($constructionIds, $referenceMonth)->anchors;
    }

    /**
     * A âncora de cada obra e, das que não têm âncora, as competências
     * canceladas que a competência absorve -- numa leitura só dos ciclos, a
     * mesma de {@see self::forConstructions()}.
     *
     * @param  iterable<int|string>  $constructionIds
     */
    public function chainsFor(iterable $constructionIds, CarbonInterface $referenceMonth): SalesBoardCompetenceChain
    {
        $ids = collect($constructionIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return SalesBoardCompetenceChain::empty();
        }

        $month = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $cyclesByConstruction = $this->candidateCycles($ids, $month);

        $anchors = [];
        $unanchored = [];

        foreach ($ids as $constructionId) {
            $walk = $this->walkBack($cyclesByConstruction[$constructionId] ?? [], $month);

            if ($walk['anchor'] !== null) {
                $anchors[$constructionId] = $walk['anchor'];
            } elseif ($walk['absorbed'] !== []) {
                $unanchored[$constructionId] = $walk['absorbed'];
            }
        }

        if ($anchors === []) {
            return new SalesBoardCompetenceChain([], $unanchored);
        }

        $linesByBaseline = $this->linesOf(array_map(
            fn (array $anchor): int => $anchor['baseline_id'],
            array_values($anchors),
        ));

        $positions = [];

        foreach ($anchors as $constructionId => $anchor) {
            $row = $anchor['row'];
            $referenceMonthOfAnchor = CarbonImmutable::parse(substr((string) $row->reference_month, 0, 10))->startOfMonth();

            $positions[$constructionId] = new SalesBoardPriorPosition(
                constructionId: $constructionId,
                cycleId: (int) $row->id,
                baselineId: $anchor['baseline_id'],
                baselineVersion: $anchor['baseline_version'],
                referenceMonth: $referenceMonthOfAnchor,
                positionDate: $row->position_date === null
                    ? $referenceMonthOfAnchor->endOfMonth()->startOfDay()
                    : CarbonImmutable::parse(substr((string) $row->position_date, 0, 10))->startOfDay(),
                isPublished: $anchor['published'],
                skippedCancelledMonths: $anchor['skipped'],
                lines: $linesByBaseline[$anchor['baseline_id']] ?? [],
            );
        }

        return new SalesBoardCompetenceChain($positions, $unanchored);
    }

    /**
     * A âncora de um ciclo -- a mesma regra, para um empreendimento.
     */
    public function forCycle(SalesBoardCycle $cycle): ?SalesBoardPriorPosition
    {
        return $this->forConstructions([(int) $cycle->construction_id], $cycle->reference_month)[(int) $cycle->construction_id] ?? null;
    }

    /**
     * Os ciclos dos meses anteriores, com a versão da publicação vigente de cada
     * um e a cobertura da automação da Emissão do ciclo. Uma consulta, agrupada
     * por obra e mês (`Y-m`).
     *
     * @param  list<int>  $constructionIds
     * @return array<int, array<string, object>>
     */
    private function candidateCycles(array $constructionIds, CarbonImmutable $month): array
    {
        $cycles = (new SalesBoardCycle)->getTable();
        $baselines = (new SalesBoardCycleBaseline)->getTable();
        $emissions = (new Emission)->getTable();

        $from = $month->subMonthsNoOverflow(self::MAXIMUM_MONTHS_BACK);
        $through = $month->subDay();

        $rows = DB::table($cycles.' as cycles')
            ->leftJoin($baselines.' as current_baseline', 'current_baseline.id', '=', 'cycles.current_baseline_id')
            ->leftJoin($emissions.' as emissions', 'emissions.id', '=', 'cycles.emission_id')
            ->whereIn('cycles.construction_id', $constructionIds)
            ->whereBetween('cycles.reference_month', [$from->toDateString(), InclusiveDateBound::upperBound($through)])
            ->select([
                'cycles.id',
                'cycles.construction_id',
                'cycles.reference_month',
                'cycles.position_date',
                'cycles.status',
                'cycles.current_baseline_id',
                'current_baseline.version as current_version',
                'emissions.sales_board_source as emission_sales_board_source',
                'emissions.sales_board_automation_start_reference_month as emission_automation_start',
            ])
            ->selectSub($this->currentPublication('publications.sales_board_cycle_baseline_id'), 'published_baseline_id')
            ->selectSub($this->currentPublication('published_baseline.version', joinBaseline: true), 'published_version')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->construction_id][substr((string) $row->reference_month, 0, 7)] = $row;
        }

        return $grouped;
    }

    /**
     * Subconsulta escalar sobre a publicação vigente (maior sequência) do ciclo.
     */
    private function currentPublication(string $column, bool $joinBaseline = false): Builder
    {
        $publications = (new SalesBoardPublication)->getTable();

        return DB::table($publications.' as publications')
            ->when($joinBaseline, fn (Builder $query): Builder => $query->join(
                (new SalesBoardCycleBaseline)->getTable().' as published_baseline',
                'published_baseline.id',
                '=',
                'publications.sales_board_cycle_baseline_id',
            ))
            ->select($column)
            ->whereColumn('publications.sales_board_cycle_id', 'cycles.id')
            ->orderByDesc('publications.sequence_number')
            ->limit(1);
    }

    /**
     * Anda para trás a partir de M-1: pula os cancelados, para no primeiro ciclo
     * que não foi cancelado ou no primeiro mês sem ciclo.
     *
     * O primeiro ciclo não cancelado é a âncora. O mês sem ciclo encerra a
     * cadeia sem âncora, e os cancelados pulados até ali são absorvidos pela
     * competência -- só os que a automação da Emissão do ciclo ainda cobre
     * ({@see self::absorbableWithoutAnchor()}). Passados os 24 meses só com
     * cancelados, nada: não é cadeia, é obra fora da automação.
     *
     * @param  array<string, object>  $cyclesByMonth
     * @return array{anchor: array{row: object, baseline_id: int, baseline_version: int, published: bool, skipped: list<CarbonImmutable>}|null, absorbed: list<CarbonImmutable>}
     */
    private function walkBack(array $cyclesByMonth, CarbonImmutable $month): array
    {
        $skipped = [];

        for ($back = 1; $back <= self::MAXIMUM_MONTHS_BACK; $back++) {
            $candidateMonth = $month->subMonthsNoOverflow($back);
            $row = $cyclesByMonth[$candidateMonth->format('Y-m')] ?? null;

            if ($row === null) {
                return ['anchor' => null, 'absorbed' => $this->absorbableWithoutAnchor($skipped, $cyclesByMonth)];
            }

            if ($row->status === SalesBoardCycleStatus::Cancelled->value) {
                $skipped[] = $candidateMonth;

                continue;
            }

            $published = $row->published_baseline_id !== null;
            $baselineId = $published ? $row->published_baseline_id : $row->current_baseline_id;

            if ($baselineId === null) {
                return ['anchor' => null, 'absorbed' => []];
            }

            return [
                'anchor' => [
                    'row' => $row,
                    'baseline_id' => (int) $baselineId,
                    'baseline_version' => (int) ($published ? $row->published_version : $row->current_version),
                    'published' => $published,
                    'skipped' => $skipped,
                ],
                'absorbed' => [],
            ];
        }

        return ['anchor' => null, 'absorbed' => []];
    }

    /**
     * Dos meses cancelados pulados até o mês sem ciclo, os que a competência
     * absorve sem âncora: a sequência, a partir de M-1, dos que a automação da
     * Emissão do ciclo cobre (Emissão automatizada e mês a partir do início).
     *
     * O primeiro que a automação não cobre encerra a sequência -- os anteriores
     * a ele também não são cobertos. Ele é do registro manual: o quadro dele é
     * revisto à mão, e absorver os fatos dele aqui daria dois donos a eles.
     *
     * @param  list<CarbonImmutable>  $skipped  do mês mais recente para o mais antigo
     * @param  array<string, object>  $cyclesByMonth
     * @return list<CarbonImmutable>
     */
    private function absorbableWithoutAnchor(array $skipped, array $cyclesByMonth): array
    {
        $absorbed = [];

        foreach ($skipped as $cancelledMonth) {
            $row = $cyclesByMonth[$cancelledMonth->format('Y-m')];

            if (! self::coveredByAutomation($row, $cancelledMonth)) {
                break;
            }

            $absorbed[] = $cancelledMonth;
        }

        return $absorbed;
    }

    /**
     * A mesma pergunta de {@see Emission::automationCovers()}, sobre as colunas
     * da Emissão trazidas na leitura dos ciclos.
     */
    private static function coveredByAutomation(object $row, CarbonImmutable $month): bool
    {
        if (($row->emission_sales_board_source ?? null) !== SalesBoardSource::Automated->value
            || ($row->emission_automation_start ?? null) === null) {
            return false;
        }

        $start = CarbonImmutable::parse(substr((string) $row->emission_automation_start, 0, 10))->startOfMonth();

        return $month->greaterThanOrEqualTo($start);
    }

    /**
     * As linhas congeladas das âncoras, numa consulta.
     *
     * @param  list<int>  $baselineIds
     * @return array<int, array<int, SalesBoardPriorLine>> versão => unidade => linha
     */
    private function linesOf(array $baselineIds): array
    {
        $lines = [];

        DB::table((new SalesBoardCycleLine)->getTable())
            ->whereIn('sales_board_cycle_baseline_id', array_values(array_unique($baselineIds)))
            ->orderBy('id')
            ->get([
                'sales_board_cycle_baseline_id',
                'construction_unit_id',
                'block',
                'unit',
                'classification',
                'contract_id',
                'contract_code',
                'contract_sale_value',
                'contract_sale_date',
                'unit_reference_value',
                'construction_unit_exchange_id',
                'exchange_value',
                'exchange_ended_on',
                'settlement_state',
            ])
            ->each(function (object $row) use (&$lines): void {
                $classification = SalesBoardUnitClassification::from((string) $row->classification);

                $lines[(int) $row->sales_board_cycle_baseline_id][(int) $row->construction_unit_id] = new SalesBoardPriorLine(
                    unitId: (int) $row->construction_unit_id,
                    classification: $classification,
                    contractId: $row->contract_id === null ? null : (int) $row->contract_id,
                    contractCode: $row->contract_code === null ? null : (string) $row->contract_code,
                    saleValueCents: IntegerMoney::cents($row->contract_sale_value),
                    saleDate: $row->contract_sale_date === null ? null : substr((string) $row->contract_sale_date, 0, 10),
                    exchangeId: $row->construction_unit_exchange_id === null ? null : (int) $row->construction_unit_exchange_id,
                    settlementState: $row->settlement_state === null ? null : ContractSettlementState::tryFrom((string) $row->settlement_state),
                    block: $row->block === null ? null : (string) $row->block,
                    unit: $row->unit === null ? null : (string) $row->unit,
                    bucketValueCents: match ($classification) {
                        SalesBoardUnitClassification::Stock => IntegerMoney::cents($row->unit_reference_value),
                        SalesBoardUnitClassification::Financed,
                        SalesBoardUnitClassification::Settled => IntegerMoney::cents($row->contract_sale_value),
                        SalesBoardUnitClassification::Exchanged => IntegerMoney::cents($row->exchange_value),
                        SalesBoardUnitClassification::Undetermined => null,
                    },
                    exchangeEndedOn: $row->exchange_ended_on === null ? null : substr((string) $row->exchange_ended_on, 0, 10),
                );
            });

        return $lines;
    }
}
