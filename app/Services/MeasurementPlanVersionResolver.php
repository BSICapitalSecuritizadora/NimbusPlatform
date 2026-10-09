<?php

namespace App\Services;

use App\Enums\MeasurementPlanVersionStatus;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Qual versão do plano de medição vale para uma competência: a regra única do
 * módulo, lida do histórico gravado das versões -- nunca da versão vigente de
 * hoje.
 *
 * Só versão que valeu (vigente ou substituída) rege competência; rascunho e
 * cancelada, nunca. A vigência começa no 1º dia do mês em que a versão foi
 * ativada (calendário de negócio, BRT) e vai até a véspera da vigência da
 * versão seguinte ({@see MeasurementPlanVersion::effectiveUntil()}). Como a
 * vigência nunca recua de uma ativação para a outra e a ordem de ativação é a
 * ordem do número da versão, a versão que rege a competência M é a de maior
 * número cuja vigência começa até M. A versão substituída no próprio mês da
 * ativação não rege mês nenhum.
 *
 * Antes da vigência da primeira versão vale a V1. É a regra que o módulo já
 * tinha: a V1 ativada depois do primeiro mês do cronograma planeja as
 * competências anteriores como medições atrasadas, que continuam a medir e
 * entram no teto de 100% ({@see MeasurementPlanVersionService::projectSchedule()}).
 * Nenhuma versão posterior alcança esses meses, porque a vigência nunca recua.
 *
 * O resultado é estável para toda medição de pé: a ativação recusa vigência
 * que comece até a competência mais recente que o plano tem de pé
 * ({@see MeasurementPlanVersionService::assertActivatable()}), então a versão
 * que regia a competência de uma medição no envio continua a regê-la. O
 * arquivo da medição congela essa versão no envio, junto com a medição
 * prevista daquela competência ({@see MeasurementAsset::$plan_version_id}), e
 * ela nunca é recalculada: a competência da medição só muda junto com a linha
 * de cada arquivo, e só para outra que a versão congelada também rege
 * ({@see Measurement}).
 *
 * As comparações são por mês ('Y-m'), dos dois lados: é o que a vigência e a
 * competência são, e o resultado não depende do dia gravado nem do formato da
 * data (o SQLite dos testes grava a data com hora, o MySQL não).
 *
 * As leituras comuns daqui contam com o lock da Operation, que o envio, a
 * edição, a aprovação e a ativação seguram antes de tudo: nenhuma ativação
 * corre no meio delas.
 */
class MeasurementPlanVersionResolver
{
    /**
     * A versão que rege a competência no plano, ou `null` quando o plano nunca
     * entrou em vigor (só tem o rascunho da V1).
     *
     * `$asOfMeasurementId` lê o histórico como ele estava quando a medição foi
     * enviada: só contam as versões ativadas antes dela
     * (`last_measurement_id_at_activation` vazio ou menor que o id dela).
     */
    public function forCompetence(MeasurementPlanSet|int $planSet, DateTimeInterface|string $competence, ?int $asOfMeasurementId = null): ?MeasurementPlanVersion
    {
        return MeasurementPlanVersion::query()
            ->where('plan_set_id', $planSet instanceof MeasurementPlanSet ? $planSet->getKey() : $planSet)
            ->governing($competence, $asOfMeasurementId)
            ->first();
    }

    /**
     * A versão rege a competência?
     */
    public function governs(MeasurementPlanVersion $version, DateTimeInterface|string|null $competence): bool
    {
        return $competence !== null
            && MeasurementPlanVersion::query()->whereKey($version->getKey())->governing($competence)->exists();
    }

    /**
     * É a primeira versão do plano que valeu? É ela que rege, além da própria
     * vigência, as competências do cronograma anteriores a ela.
     */
    public function isFirstEffective(MeasurementPlanVersion $version): bool
    {
        return $version->status->hasBeenEffective()
            && ! MeasurementPlanVersion::query()
                ->where('plan_set_id', $version->plan_set_id)
                ->whereIn('status', [MeasurementPlanVersionStatus::Active->value, MeasurementPlanVersionStatus::Superseded->value])
                ->where('version_number', '<', $version->version_number)
                ->exists();
    }

    /**
     * O 1º dia do mês da competência, à meia-noite. A data é civil -- o mês de
     * uma linha do cronograma ou da competência da medição --, e não um
     * instante: não se converte de fuso.
     */
    public static function competenceStart(DateTimeInterface|string $competence): CarbonImmutable
    {
        $date = $competence instanceof DateTimeInterface
            ? $competence->format('Y-m-d')
            : (preg_match('/^\d{4}-\d{2}$/', $competence) === 1 ? $competence.'-01' : $competence);

        return CarbonImmutable::parse(substr($date, 0, 10))->startOfMonth();
    }

    /**
     * O mês ('Y-m') de uma coluna de data, para comparar competências na
     * consulta: o SQLite dos testes grava a data com hora, o MySQL não.
     */
    public static function monthKey(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /**
     * Restringe a consulta de versões (`measurement_plan_versions`, com o
     * apelido `$alias`) às que regem a competência informada.
     *
     * `$asOf` é o id da medição (ou a coluna que o traz, numa subconsulta
     * correlacionada) cujo envio delimita o histórico.
     */
    public static function whereGovernsCompetence(QueryBuilder $versions, string $alias, DateTimeInterface|string $competence, int|Expression|null $asOf = null): QueryBuilder
    {
        $month = self::competenceStart($competence)->format('Y-m');

        return self::constrainToGoverning(
            $versions,
            $alias,
            fn (QueryBuilder $query, string $column): QueryBuilder => $query->whereRaw(self::monthKey($column).' <= ?', [$month]),
            $asOf,
        );
    }

    /**
     * Restringe a consulta de versões às que regem a competência de uma linha
     * do cronograma da consulta externa (`$lineDateColumn`).
     */
    public static function whereGovernsLineCompetence(QueryBuilder $versions, string $alias, string $lineDateColumn, int|Expression|null $asOf = null): QueryBuilder
    {
        return self::constrainToGoverning(
            $versions,
            $alias,
            fn (QueryBuilder $query, string $column): QueryBuilder => $query->whereRaw(self::monthKey($column).' <= '.self::monthKey($lineDateColumn)),
            $asOf,
        );
    }

    /**
     * A regra em SQL: versão que valeu, cuja vigência começa até o mês da
     * competência -- ou que é a primeira que valeu, e rege também o que vem
     * antes da vigência dela --, sem outra posterior que também já tenha
     * começado.
     *
     * @param  Closure(QueryBuilder, string): QueryBuilder  $startsBy  vigência (coluna informada) que começa até o mês da competência
     */
    private static function constrainToGoverning(QueryBuilder $versions, string $alias, Closure $startsBy, int|Expression|null $asOf): QueryBuilder
    {
        $effective = [MeasurementPlanVersionStatus::Active->value, MeasurementPlanVersionStatus::Superseded->value];
        $earlier = "{$alias}_earlier";
        $later = "{$alias}_later";
        $activatedBefore = fn (QueryBuilder $query, string $table): QueryBuilder => $asOf === null
            ? $query
            : $query->where(fn (QueryBuilder $before): QueryBuilder => $before
                ->whereNull("{$table}.last_measurement_id_at_activation")
                ->orWhere("{$table}.last_measurement_id_at_activation", '<', $asOf));

        return $activatedBefore($versions, $alias)
            ->whereIn("{$alias}.status", $effective)
            ->whereNotNull("{$alias}.effective_from")
            ->where(fn (QueryBuilder $starts): QueryBuilder => $startsBy($starts, "{$alias}.effective_from")
                ->orWhereNotExists(fn (QueryBuilder $previous): QueryBuilder => $activatedBefore($previous, $earlier)
                    ->from("measurement_plan_versions as {$earlier}")
                    ->whereColumn("{$earlier}.plan_set_id", "{$alias}.plan_set_id")
                    ->whereIn("{$earlier}.status", $effective)
                    ->whereColumn("{$earlier}.version_number", '<', "{$alias}.version_number")))
            ->whereNotExists(fn (QueryBuilder $next): QueryBuilder => $startsBy($activatedBefore($next, $later)
                ->from("measurement_plan_versions as {$later}")
                ->whereColumn("{$later}.plan_set_id", "{$alias}.plan_set_id")
                ->whereIn("{$later}.status", $effective)
                ->whereColumn("{$later}.version_number", '>', "{$alias}.version_number"), "{$later}.effective_from"));
    }

    /**
     * Os planos da lista que preveem medição na competência pela versão que a
     * rege -- com `$asOfMeasurementId`, a que a regia no envio dessa medição
     * --, em ordem de id.
     *
     * @param  list<int>  $planSetIds
     * @return list<int>
     */
    public function planSetsPlannedIn(array $planSetIds, DateTimeInterface|string $competence, ?int $asOfMeasurementId = null): array
    {
        if ($planSetIds === []) {
            return [];
        }

        return MeasurementPlanLine::query()
            ->whereIn('plan_set_id', $planSetIds)
            ->governingTheirCompetence($asOfMeasurementId)
            ->inCompetence($competence)
            ->distinct()
            ->orderBy('plan_set_id')
            ->pluck('plan_set_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
