<?php

namespace Tests\Support;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Obra com cronograma mensal e medições que passam pelo fluxo real da
 * Engenharia -- o mesmo caminho que trava, valida e congela o snapshot.
 *
 * É classe, e não função de arquivo de teste, para também servir aos processos
 * filhos dos testes do grupo `mysql`, que só enxergam o autoload `Tests\`.
 * O disco `local` precisa estar preparado por quem chama (`Storage::fake()` no
 * SQLite; a raiz do pai nos processos filhos).
 */
final class MeasurementPhysicalProgressScenario
{
    /**
     * @param  list<string>  $months  meses do cronograma ('Y-m'), na ordem das sequências
     * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}
     */
    public static function plan(array $months = ['2026-05', '2026-06', '2026-07'], string $initialPercent = '0.00', ?string $referenceDate = null): array
    {
        config()->set('filesystems.private_disk', 'local');

        $actor = User::factory()->withTwoFactor()->create();
        $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize']);

        $operation = Operation::factory()->create([
            'status' => 'active',
            'assigned_user_id' => $actor->id,
            'responsible_user_id' => $actor->id,
            'stage2_reviewer_user_id' => $actor->id,
            'stage3_reviewer_user_id' => $actor->id,
            'payment_manager_user_id' => $actor->id,
            'payment_receipt_uploader_user_id' => $actor->id,
            'payment_finalizer_user_id' => $actor->id,
        ]);

        $planSet = MeasurementPlanSet::factory()->default()->create([
            'operation_id' => $operation->id,
            'construction_fund_amount' => '1000000.00',
            'initial_incurred_amount' => '0.00',
            'initial_physical_progress_percent' => $initialPercent,
            'initial_physical_progress_reference_date' => $referenceDate
                ?? ((float) $initialPercent > 0 ? Carbon::parse($months[0].'-01')->subDay()->toDateString() : null),
        ]);

        $lines = [];

        foreach (array_values($months) as $index => $month) {
            $lines[$month] = MeasurementPlanLine::factory()->create([
                'operation_id' => $operation->id,
                'plan_set_id' => $planSet->id,
                'sequence_number' => $index + 1,
                'measurement_date' => $month.'-01',
                'planned_monthly_percent' => 10,
                'planned_cumulative_percent' => 10 * ($index + 1),
                'initial_realized_cumulative_percent' => 0,
                'realized_monthly_percent' => 0,
                'realized_cumulative_percent' => 0,
            ]);
        }

        return compact('actor', 'operation', 'planSet', 'lines');
    }

    /**
     * Avanço inicial como a migration o copia do cronograma antigo: percentual
     * sem data de referência, estado que a criação do plano não aceita.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function copiedInitialProgress(array $scenario, string $percent): void
    {
        DB::table('measurement_plan_sets')->where('id', $scenario['planSet']->id)->update([
            'initial_physical_progress_percent' => $percent,
            'initial_physical_progress_reference_date' => null,
        ]);

        $scenario['planSet']->refresh();
    }

    /**
     * Medição enviada para a linha do mês e aguardando a Engenharia.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function measurement(array $scenario, string $month): Measurement
    {
        $line = $scenario['lines'][$month];
        $measurement = Measurement::factory()->create([
            'operation_id' => $scenario['operation']->id,
            'reference_month' => $line->measurement_date->toDateString(),
            'status' => 'pending',
            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
            'storage_path' => null,
            'filename' => null,
            'uploaded_by' => $scenario['actor']->id,
        ]);
        $path = "nimbus_docs/measurements/assets/physical-progress-{$measurement->id}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7 avanço físico {$measurement->id}");
        $measurement->assets()->create([
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $line->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);

        app(MeasurementWorkflow::class)->startReview($measurement, $scenario['actor']);

        return $measurement->fresh();
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function approveEngineering(array $scenario, Measurement $measurement, int|float|string $percent): void
    {
        app(MeasurementWorkflow::class)->approve(
            $measurement->fresh(),
            $scenario['actor'],
            engineeringProgress: [$scenario['planSet']->id => $percent],
        );
    }

    /**
     * Medição enviada e aprovada pela Engenharia com o percentual do mês.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function measured(array $scenario, string $month, int|float|string $percent): Measurement
    {
        $measurement = self::measurement($scenario, $month);
        self::approveEngineering($scenario, $measurement, $percent);

        return $measurement->fresh();
    }

    /**
     * A Gestão recusa e a medição volta à Engenharia: a aprovação deixa de valer.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function returnToEngineering(array $scenario, Measurement $measurement): void
    {
        app(MeasurementWorkflow::class)->reject($measurement->fresh(), $scenario['actor'], 'Corrigir o percentual medido.');
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function progress(array $scenario): MeasurementPhysicalProgress
    {
        return app(MeasurementPhysicalProgressService::class)->forPlanSet($scenario['planSet']->fresh());
    }
}
