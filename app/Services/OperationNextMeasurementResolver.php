<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OperationNextMeasurementResolver
{
    /**
     * Resolve na consulta, sem persistir next_measurement_at nem consultar por registro.
     * O plano é o padrão, ou o primeiro cadastrado, entre os vigentes (com
     * versão ativada) -- como Operation::defaultPlanSet(), mas sem o plano em
     * rascunho, que ainda não recebe medição: com o padrão em rascunho, a
     * próxima medição vem do vigente seguinte. Cada competência do cronograma
     * vem da versão que a rege
     * ({@see MeasurementPlanLine::scopeGoverningTheirCompetence()}): a
     * competência atrasada de uma versão já substituída é sugerida pela linha
     * dela, que é a que o Enviar Medição oferece, e não pela cópia da vigente.
     * Pendente é a linha que ainda pode receber medição
     * ({@see MeasurementPlanLine::scopeAvailableForMeasurement()}): nem
     * reivindicada por Engenharia vigente, nem ocupada por outra medição aberta,
     * finalizada ou com pagamento. As colunas `realized_*`/`measurement_id` da
     * linha não decidem: elas guardam a última aprovação mesmo depois de ela
     * deixar de valer, e prendiam para sempre a competência de uma medição
     * recusada, excluída ou reaprovada em outra linha. A competência que a
     * operação já mediu sem o plano também não é sugerida
     * ({@see MeasurementPlanLine::scopeCompetenceMeasuredWithoutThePlan()}):
     * enquanto aquela medição estiver de pé, ela não tem como ser medida.
     * A ordem é a do cronograma -- competência, e no mesmo mês a sequência (a
     * posição de {@see MeasurementPhysicalProgress}) --
     * e prevalece sobre a data atual, inclusive para atrasos: uma revisão que
     * acrescenta medição prevista com número maior num mês anterior não a
     * esconde.
     *
     * @param  Builder<Operation>  $operations
     * @return Builder<Operation>
     */
    public function addNextMeasurementDate(Builder $operations): Builder
    {
        $planSet = new MeasurementPlanSet;
        $line = new MeasurementPlanLine;

        $defaultPlan = MeasurementPlanSet::query()
            ->select($planSet->getQualifiedKeyName())
            ->whereColumn($planSet->qualifyColumn('operation_id'), $line->qualifyColumn('operation_id'))
            ->whereHas('activeVersion')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->limit(1);

        $nextDate = MeasurementPlanLine::query()
            ->select($line->qualifyColumn('measurement_date'))
            ->whereColumn($line->qualifyColumn('operation_id'), $operations->getModel()->getQualifiedKeyName())
            ->where('plan_set_id', $defaultPlan)
            ->governingTheirCompetence()
            ->availableForMeasurement()
            ->whereNot(fn (Builder $orphans): Builder => $orphans->competenceMeasuredWithoutThePlan())
            ->whereDoesntHave('planSet', fn (Builder $plans): Builder => $this->coveringInitialProgress($plans, $line))
            ->orderBy('measurement_date')
            ->orderBy('sequence_number')
            ->limit(1);

        return $operations
            ->addSelect(['next_pending_measurement_at' => $nextDate])
            ->withCasts(['next_pending_measurement_at' => 'date']);
    }

    /**
     * O plano cujo avanço físico inicial já cobre a competência da linha: ela
     * termina até a data a que o avanço inicial se refere, e a Engenharia
     * recusaria avanço nela (MeasurementPhysicalProgress::initialProgressCovers).
     * "Termina até a data" é o mesmo que começar antes do mês do dia seguinte à
     * data de referência.
     *
     * @param  Builder<MeasurementPlanSet>  $plans
     * @return Builder<MeasurementPlanSet>
     */
    private function coveringInitialProgress(Builder $plans, MeasurementPlanLine $line): Builder
    {
        $reference = $plans->qualifyColumn('initial_physical_progress_reference_date');
        $firstUncoveredMonth = DB::getDriverName() === 'sqlite'
            ? "date({$reference}, '+1 day', 'start of month')"
            : "DATE_FORMAT(DATE_ADD({$reference}, INTERVAL 1 DAY), '%Y-%m-01')";

        return $plans
            ->where($plans->qualifyColumn('initial_physical_progress_percent'), '>', 0)
            ->whereNotNull($reference)
            ->whereRaw("{$line->qualifyColumn('measurement_date')} < {$firstUncoveredMonth}");
    }
}
