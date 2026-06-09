<?php

namespace App\Services;

use App\DTOs\ConstructionProgressData;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class MeasurementPlanProgressProvider implements ConstructionProgressProvider
{
    public function forEmission(
        Emission $emission,
        CarbonInterface $referenceMonth,
        ?Construction $construction = null,
    ): ?ConstructionProgressData {
        $monthStart = Carbon::parse($referenceMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $line = $this->resolveLine($emission, $construction, $monthStart, $monthEnd, true)
            ?? $this->resolveLine($emission, $construction, $monthStart, $monthEnd, false);

        if (! $line instanceof MeasurementPlanLine) {
            return null;
        }

        return new ConstructionProgressData(
            planName: $line->planSet?->name,
            plannedMonthlyPercent: (float) $line->planned_monthly_percent,
            plannedCumulativePercent: (float) $line->planned_cumulative_percent,
            realizedMonthlyPercent: (float) $line->realized_monthly_percent,
            realizedCumulativePercent: (float) $line->realized_cumulative_percent,
            diffPercent: (float) $line->evolution_diff_percent,
            trend: $line->evolution_trend,
            measurementDate: $line->measurement_date,
        );
    }

    private function resolveLine(
        Emission $emission,
        ?Construction $construction,
        CarbonInterface $monthStart,
        CarbonInterface $monthEnd,
        bool $defaultPlanOnly,
    ): ?MeasurementPlanLine {
        $base = fn (): Builder => $this->baseQuery($emission, $construction, $defaultPlanOnly);

        return $base()
            ->whereBetween('measurement_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderByDesc('measurement_date')
            ->orderByDesc('sequence_number')
            ->first()
            ?? $base()
                ->where('measurement_date', '<=', $monthEnd->toDateString())
                ->orderByDesc('measurement_date')
                ->orderByDesc('sequence_number')
                ->first();
    }

    private function baseQuery(Emission $emission, ?Construction $construction, bool $defaultPlanOnly): Builder
    {
        return MeasurementPlanLine::query()
            ->with('planSet')
            ->whereNotNull('measurement_date')
            ->when($defaultPlanOnly, fn (Builder $query): Builder => $query->whereHas(
                'planSet',
                fn (Builder $planSet): Builder => $planSet->where('is_default', true),
            ))
            ->whereHas('operation', fn (Builder $operation): Builder => $operation->where('emission_id', $emission->id))
            ->when($construction instanceof Construction, fn (Builder $query): Builder => $query->where(
                fn (Builder $scoped): Builder => $scoped
                    ->whereHas('planSet', fn (Builder $planSet): Builder => $planSet->where('construction_id', $construction->id))
                    ->orWhere(fn (Builder $fallback): Builder => $fallback
                        ->whereDoesntHave('planSet', fn (Builder $planSet): Builder => $planSet->whereNotNull('construction_id'))
                        ->whereHas('operation', fn (Builder $operation): Builder => $operation->where('construction_id', $construction->id)),
                    ),
            ));
    }
}
