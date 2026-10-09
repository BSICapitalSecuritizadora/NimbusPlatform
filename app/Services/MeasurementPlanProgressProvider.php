<?php

namespace App\Services;

use App\DTOs\ConstructionProgressData;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Evolução da obra para o relatório mensal da emissão.
 *
 * O previsto continua vindo da linha do cronograma do mês (ou da última antes
 * dele). O realizado não: a data prevista existe em toda linha e não prova
 * medição nenhuma, e a linha guarda zero quando não foi medida -- o relatório
 * publicava 0% e uma queda que não aconteceu. O realizado vem de
 * {@see MeasurementPhysicalProgressService}: o acumulado conhecido até o fim do
 * mês (avanço inicial do plano mais as medições com Engenharia vigente) e, como
 * mensal, só o que foi medido naquele mês. Antes de haver qualquer realizado
 * conhecido, o empreendimento fica sem linha de progresso no mês.
 */
class MeasurementPlanProgressProvider implements ConstructionProgressProvider
{
    /** @var array<int, array<int, MeasurementPhysicalProgress>> progresso por operação, lido uma vez por relatório */
    private array $progressByOperation = [];

    public function __construct(private MeasurementPhysicalProgressService $physicalProgress) {}

    public function forEmission(
        Emission $emission,
        CarbonInterface $referenceMonth,
        ?Construction $construction = null,
    ): ?ConstructionProgressData {
        $monthStart = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $line = $this->resolveLine($emission, $construction, $monthStart, $monthEnd, true)
            ?? $this->resolveLine($emission, $construction, $monthStart, $monthEnd, false);

        if (! $line instanceof MeasurementPlanLine) {
            return null;
        }

        $progress = $this->progressFor($line);

        // Nada medido até o mês e nenhum avanço inicial em vigor nele: não há
        // realizado a publicar -- "não medido" não é 0%.
        if (! $progress->isKnownThroughDate($monthEnd)) {
            return null;
        }

        $measured = $progress->contributionsWithin($monthStart, $monthEnd);
        $realizedCumulative = $progress->cumulativeThroughDate($monthEnd);
        $diff = $realizedCumulative - (IntegerMoney::basisPoints($line->planned_cumulative_percent) ?? 0);
        $lastMeasurement = $measured === [] ? null : $measured[array_key_last($measured)];

        return new ConstructionProgressData(
            planName: $line->planSet?->name,
            plannedMonthlyPercent: $this->percent(IntegerMoney::basisPoints($line->planned_monthly_percent) ?? 0),
            plannedCumulativePercent: $this->percent(IntegerMoney::basisPoints($line->planned_cumulative_percent) ?? 0),
            realizedMonthlyPercent: $this->percent(array_sum(array_map(
                fn (MeasurementPhysicalProgressContribution $contribution): int => $contribution->basisPoints,
                $measured,
            ))),
            realizedCumulativePercent: $this->percent($realizedCumulative),
            diffPercent: $this->percent($diff),
            trend: MeasurementPlanLine::resolveTrend((float) $diff),
            measurementDate: $lastMeasurement?->measurementDate,
            measuredInMonth: $measured !== [],
        );
    }

    private function progressFor(MeasurementPlanLine $line): MeasurementPhysicalProgress
    {
        $operationId = (int) $line->operation_id;
        $this->progressByOperation[$operationId] ??= $this->physicalProgress->forOperation($operationId);

        return $this->progressByOperation[$operationId][(int) $line->plan_set_id]
            ?? new MeasurementPhysicalProgress((int) $line->plan_set_id, 0, null, []);
    }

    /**
     * O DTO do relatório é de apresentação e trabalha em float; a conta foi
     * feita antes, em basis points.
     */
    private function percent(int $basisPoints): float
    {
        return (float) MeasurementPhysicalProgress::decimal($basisPoints);
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
            ->where('measurement_date', '>=', $monthStart->toDateString())
            ->where('measurement_date', '<=', InclusiveDateBound::upperBound($monthEnd))
            ->orderByDesc('measurement_date')
            ->orderByDesc('sequence_number')
            ->first()
            ?? $base()
                ->where('measurement_date', '<=', InclusiveDateBound::upperBound($monthEnd))
                ->orderByDesc('measurement_date')
                ->orderByDesc('sequence_number')
                ->first();
    }

    private function baseQuery(Emission $emission, ?Construction $construction, bool $defaultPlanOnly): Builder
    {
        // Cada competência pela versão do plano que a rege -- a mesma linha
        // que a medição daquela competência congela. O previsto é o mesmo da
        // cópia que a vigente traz (a ativação recusa reescrever o passado).
        return MeasurementPlanLine::query()
            ->with('planSet')
            ->governingTheirCompetence()
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
