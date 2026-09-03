<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Fonte única da resposta para "quanto valia esta unidade nesta data?".
 *
 * A regra, fechada:
 *
 * 1. a última linha do histórico com `effective_from <= data`, desempatada pelo
 *    maior `id` -- é isso que faz a correção lançada depois prevalecer sobre o
 *    valor errado lançado antes para a mesma vigência;
 * 2. na falta de histórico, o valor base da unidade, e **somente** se a data de
 *    referência dele também não ultrapassar a data consultada;
 * 3. caso contrário, ausência explícita.
 *
 * Um valor programado para o futuro nunca contamina uma consulta anterior: é o
 * passo 1 que garante isso, e é por ele que registrar hoje um reajuste para
 * outubro não muda o que a unidade valia em julho.
 *
 * A API privilegia lote. Resolver unidade por unidade numa obra de centenas
 * dispararia uma consulta por unidade, e a Fase B vai varrer a carteira inteira.
 */
class UnitValueResolver
{
    /**
     * Valor vigente de cada unidade do empreendimento na data.
     *
     * Duas consultas, independentemente do número de unidades.
     *
     * @return array<int, ResolvedUnitValue> indexado por `construction_unit_id`
     */
    public function forConstruction(Construction $construction, CarbonInterface $positionDate): array
    {
        $units = ConstructionUnit::query()
            ->where('construction_id', $construction->getKey())
            ->get(['id', 'construction_id', 'base_value', 'base_value_reference_date']);

        return $this->forUnits($units, $positionDate);
    }

    /**
     * Valor vigente de uma unidade na data.
     *
     * Atalho de conveniência para os fluxos que já têm a unidade em mãos --
     * formulário, avaliação de um contrato. Quem precisa de muitas unidades usa
     * {@see self::forConstruction()} ou {@see self::forUnits()}.
     */
    public function forUnit(ConstructionUnit $constructionUnit, CarbonInterface $positionDate): ResolvedUnitValue
    {
        $unitId = (int) $constructionUnit->getKey();

        return $this->forUnits(collect([$constructionUnit]), $positionDate)[$unitId]
            ?? ResolvedUnitValue::absent($unitId, $this->normalizeDate($positionDate));
    }

    /**
     * Resolve um conjunto qualquer de unidades já carregadas.
     *
     * Uma única consulta ao histórico para todas elas.
     *
     * @param  Collection<int, ConstructionUnit>  $units
     * @return array<int, ResolvedUnitValue> indexado por `construction_unit_id`
     */
    public function forUnits(Collection $units, CarbonInterface $positionDate): array
    {
        $date = $this->normalizeDate($positionDate);
        $unitIds = $units->map(fn (ConstructionUnit $unit): int => (int) $unit->getKey())->all();

        $historyByUnit = $this->latestHistoryByUnit($unitIds, $date);

        $resolved = [];

        foreach ($units as $unit) {
            $unitId = (int) $unit->getKey();
            $resolved[$unitId] = $this->resolve($unit, $historyByUnit[$unitId] ?? null, $date);
        }

        return $resolved;
    }

    private function resolve(ConstructionUnit $unit, ?ConstructionUnitValue $history, CarbonImmutable $date): ResolvedUnitValue
    {
        $unitId = (int) $unit->getKey();

        if ($history instanceof ConstructionUnitValue) {
            $valueCents = IntegerMoney::cents($history->value);

            if ($valueCents !== null) {
                return ResolvedUnitValue::fromHistory(
                    constructionUnitId: $unitId,
                    positionDate: $date,
                    valueCents: $valueCents,
                    effectiveFrom: $this->normalizeDate($history->effective_from),
                );
            }
        }

        return $this->resolveFromBaseValue($unit, $date);
    }

    /**
     * O valor base só responde pela data se a referência dele já tiver chegado.
     * Uma unidade cujo valor base passa a valer em agosto não vale nada apurado
     * em julho -- afirmar o contrário seria aplicar retroativamente um preço que
     * ainda não existia.
     */
    private function resolveFromBaseValue(ConstructionUnit $unit, CarbonImmutable $date): ResolvedUnitValue
    {
        $unitId = (int) $unit->getKey();

        if (($unit->base_value === null) || ($unit->base_value_reference_date === null)) {
            return ResolvedUnitValue::absent($unitId, $date);
        }

        $referenceDate = $this->normalizeDate($unit->base_value_reference_date);

        if ($referenceDate->greaterThan($date)) {
            return ResolvedUnitValue::absent($unitId, $date);
        }

        $valueCents = IntegerMoney::cents($unit->base_value);

        if ($valueCents === null) {
            return ResolvedUnitValue::absent($unitId, $date);
        }

        return ResolvedUnitValue::fromBase(
            constructionUnitId: $unitId,
            positionDate: $date,
            valueCents: $valueCents,
            effectiveFrom: $referenceDate,
        );
    }

    /**
     * Última linha vigente de cada unidade, numa consulta só.
     *
     * A ordenação decide o vencedor em memória em vez de pedir ao banco uma
     * window function: `effective_from` crescente e `id` crescente fazem a
     * última linha escrita para cada unidade sobrescrever as anteriores no
     * índice, que é exatamente a regra de desempate -- e roda igual em SQLite e
     * MySQL.
     *
     * @param  list<int>  $unitIds
     * @return array<int, ConstructionUnitValue>
     */
    private function latestHistoryByUnit(array $unitIds, CarbonImmutable $date): array
    {
        if ($unitIds === []) {
            return [];
        }

        $latest = [];

        ConstructionUnitValue::query()
            ->whereIn('construction_unit_id', $unitIds)
            ->where('effective_from', '<=', InclusiveDateBound::upperBound($date))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->each(function (ConstructionUnitValue $value) use (&$latest): void {
                $latest[(int) $value->construction_unit_id] = $value;
            });

        return $latest;
    }

    private function normalizeDate(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->toDateString());
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }
}
