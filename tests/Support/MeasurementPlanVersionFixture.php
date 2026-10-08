<?php

namespace Tests\Support;

use App\Enums\MeasurementPlanVersionStatus;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Services\MeasurementPlanVersionService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Coloca em vigor o rascunho de cada plano, para montar cenários.
 *
 * Todo plano nasce com a V1 em rascunho e só recebe medição depois de ativado.
 * Os testes que não tratam do versionamento precisam de um plano vigente com o
 * cronograma que montaram; aqui a transição é a mesma da ativação (rascunho →
 * vigente, a anterior → substituída), sem as conferências de vigência e de
 * cronograma da {@see MeasurementPlanVersionService::activate()}
 * -- os testes do versionamento usam o serviço. É classe, e não função de
 * arquivo de teste, para servir também aos processos filhos do grupo `mysql`.
 */
final class MeasurementPlanVersionFixture
{
    public static function activate(MeasurementPlanSet ...$planSets): void
    {
        foreach ($planSets as $planSet) {
            self::activateDraftOf((int) $planSet->getKey());
            $planSet->unsetRelation('activeVersion')->unsetRelation('draftVersion');
        }
    }

    public static function activateOperation(Operation $operation): void
    {
        MeasurementPlanSet::query()
            ->where('operation_id', $operation->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (mixed $planSetId) => self::activateDraftOf((int) $planSetId));
    }

    /**
     * A versão vigente do plano, ativando o rascunho antes se ainda não houver.
     */
    public static function activeVersion(MeasurementPlanSet $planSet): MeasurementPlanVersion
    {
        self::activate($planSet);

        return MeasurementPlanVersion::query()->where('plan_set_id', $planSet->getKey())->active()->firstOrFail();
    }

    private static function activateDraftOf(int $planSetId): void
    {
        DB::transaction(function () use ($planSetId): void {
            $draft = MeasurementPlanVersion::query()->where('plan_set_id', $planSetId)->draft()->first();

            if (! $draft instanceof MeasurementPlanVersion) {
                return;
            }

            $active = MeasurementPlanVersion::query()->where('plan_set_id', $planSetId)->active()->first();
            $firstLine = MeasurementPlanLine::query()->where('plan_version_id', $draft->getKey())->min('measurement_date');
            $effectiveFrom = $draft->effective_from?->toDateString()
                ?? ($firstLine === null
                    ? CarbonImmutable::parse(BusinessTime::dateString())->startOfMonth()->toDateString()
                    : CarbonImmutable::parse((string) $firstLine)->startOfMonth()->toDateString());
            $now = now();

            if ($active instanceof MeasurementPlanVersion) {
                $active->forceFill([
                    'status' => MeasurementPlanVersionStatus::Superseded,
                    'superseded_at' => $now,
                    'superseded_by_version_id' => $draft->getKey(),
                ])->save();
            }

            $draft->forceFill([
                'status' => MeasurementPlanVersionStatus::Active,
                'effective_from' => $effectiveFrom,
                'activated_at' => $now,
                // Como a ativação do serviço: a medição enviada antes não
                // precisa cobrir o plano que só passou a valer depois.
                'last_measurement_id_at_activation' => Measurement::query()->where('operation_id', $draft->operation_id)->max('id'),
            ])->save();
        });
    }
}
