<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OperationNextMeasurementResolver
{
    /**
     * Resolve na consulta, sem persistir next_measurement_at nem consultar por registro.
     * O plano segue Operation::defaultPlanSet(): padrão, ou o primeiro cadastrado.
     * Pendente segue o cronograma: sem realizado mensal/acumulado. O vínculo da
     * Engenharia consome a linha; assets de medições abertas/finalizadas também
     * a ocupam. Recusa sem realização permite reapresentação da competência.
     * A sequência do plano prevalece sobre a data atual, inclusive para atrasos.
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
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->limit(1);

        $nextDate = MeasurementPlanLine::query()
            ->select($line->qualifyColumn('measurement_date'))
            ->whereColumn($line->qualifyColumn('operation_id'), $operations->getModel()->getQualifiedKeyName())
            ->where('plan_set_id', $defaultPlan)
            ->whereNotNull('measurement_date')
            ->where('realized_monthly_percent', '<=', 0)
            ->where('realized_cumulative_percent', '<=', 0)
            ->whereNull('measurement_id')
            ->whereDoesntHave('assets.measurement', fn (Builder $measurements): Builder => $measurements
                ->whereIn('status', [...Measurement::OPEN_STATUSES, 'finalized']))
            ->whereDoesntHave('planSet', fn (Builder $plans): Builder => $this->coveringInitialProgress($plans, $line))
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
