<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;

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
            ->orderBy('sequence_number')
            ->limit(1);

        return $operations
            ->addSelect(['next_pending_measurement_at' => $nextDate])
            ->withCasts(['next_pending_measurement_at' => 'date']);
    }
}
