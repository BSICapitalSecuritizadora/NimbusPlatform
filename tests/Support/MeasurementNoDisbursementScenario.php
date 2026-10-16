<?php

namespace Tests\Support;

use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Operação com um ou mais empreendimentos -- cada um com a própria obra, o
 * próprio Fundo de Obra na V1 (inclusive nulo ou zero) e o próprio avanço
 * inicial -- e medições originais que passam pelo fluxo real até a etapa
 * Pagamento, a decisão sem desembolso e a Finalização.
 *
 * Classe, e não função de arquivo de teste, para servir também aos processos
 * filhos do grupo `mysql`, que só enxergam o autoload `Tests\`. O disco
 * `local` precisa estar preparado por quem chama, e as notificações, falsas.
 */
final class MeasurementNoDisbursementScenario
{
    public const JUSTIFICATION = 'Competência sem avanço físico: o valor aprovado pela Engenharia é zero e não há desembolso devido.';

    /**
     * @param  list<array{fund: string|null, name?: string, initial?: string, reference_date?: string|null}>  $developments
     * @param  list<string>  $months  meses do cronograma ('Y-m'), na ordem das sequências
     * @return array{actor: User, finalizer: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}
     */
    public static function plan(array $developments = [['fund' => '1000000.00']], array $months = ['2026-05', '2026-06', '2026-07']): array
    {
        config()->set('filesystems.private_disk', 'local');

        $actor = User::factory()->withTwoFactor()->create();
        $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize']);
        // A Finalização é de outra pessoa: quem decide sem desembolso, paga ou
        // envia comprovante não dá a ciência nem aceita o que fez.
        $finalizer = User::factory()->withTwoFactor()->create();
        $finalizer->givePermissionTo(['measurements.view', 'measurements.finalize']);

        $operation = Operation::factory()->create([
            'status' => 'active',
            'assigned_user_id' => $actor->id,
            'responsible_user_id' => $actor->id,
            'stage2_reviewer_user_id' => $actor->id,
            'stage3_reviewer_user_id' => $actor->id,
            'payment_manager_user_id' => $actor->id,
            'payment_receipt_uploader_user_id' => $actor->id,
            'payment_finalizer_user_id' => $finalizer->id,
        ]);

        $planSets = [];
        $lines = [];

        foreach (array_values($developments) as $index => $development) {
            $initial = $development['initial'] ?? '0.00';
            $construction = Construction::factory()->create([
                'development_name' => $development['name'] ?? 'Torre '.chr(65 + $index),
            ]);
            $planSet = MeasurementPlanSet::factory()->withConstructionFund($development['fund'])->create([
                'operation_id' => $operation->id,
                'construction_id' => $construction->id,
                'name' => 'Plano '.($index + 1),
                'is_default' => $index === 0,
                'initial_incurred_amount' => '0.00',
                'initial_physical_progress_percent' => $initial,
                'initial_physical_progress_reference_date' => array_key_exists('reference_date', $development)
                    ? $development['reference_date']
                    : ((float) $initial > 0 ? Carbon::parse($months[0].'-01')->subDay()->toDateString() : null),
            ]);
            $planLines = [];

            foreach (array_values($months) as $sequence => $month) {
                $planLines[$month] = MeasurementPlanLine::factory()->create([
                    'operation_id' => $operation->id,
                    'plan_set_id' => $planSet->id,
                    'sequence_number' => $sequence + 1,
                    'measurement_date' => $month.'-01',
                    'planned_monthly_percent' => 10,
                    'planned_cumulative_percent' => 10 * ($sequence + 1),
                    'initial_realized_cumulative_percent' => 0,
                    'realized_monthly_percent' => 0,
                    'realized_cumulative_percent' => 0,
                ]);
            }

            // A medição só é enviada sob a versão que rege a competência: as
            // linhas entram no rascunho da V1, que é ativado antes do arquivo.
            MeasurementPlanVersionFixture::activate($planSet);

            $planSets[] = $planSet;
            $lines[] = $planLines;
        }

        return compact('actor', 'finalizer', 'operation', 'planSets', 'lines');
    }

    /**
     * Medição original enviada com um arquivo por empreendimento, aguardando
     * a Engenharia.
     *
     * @param  array{actor: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}  $scenario
     */
    public static function measurement(array $scenario, string $month): Measurement
    {
        $measurement = Measurement::factory()->create([
            'operation_id' => $scenario['operation']->id,
            'reference_month' => $month.'-01',
            'status' => 'pending',
            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
            'storage_path' => null,
            'filename' => null,
            'uploaded_by' => $scenario['actor']->id,
        ]);

        foreach ($scenario['planSets'] as $index => $planSet) {
            $path = "nimbus_docs/measurements/assets/no-disbursement-{$measurement->id}-{$index}.pdf";
            Storage::disk('local')->put($path, "%PDF-1.7 sem desembolso {$measurement->id} {$index}");
            $measurement->assets()->create([
                'plan_set_id' => $planSet->id,
                'plan_line_id' => $scenario['lines'][$index][$month]->id,
                'storage_path' => $path,
                'storage_disk' => 'local',
            ]);
        }

        app(MeasurementWorkflow::class)->startReview($measurement, $scenario['actor']);

        return $measurement->fresh();
    }

    /**
     * Medição enviada e aprovada pela Engenharia, com o realizado do mês de
     * cada empreendimento (na ordem dos empreendimentos do cenário).
     *
     * @param  array{actor: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}  $scenario
     * @param  list<int|float|string>  $percents
     */
    public static function measured(array $scenario, string $month, array $percents): Measurement
    {
        $measurement = self::measurement($scenario, $month);
        $progress = [];

        foreach ($scenario['planSets'] as $index => $planSet) {
            $progress[$planSet->id] = $percents[$index];
        }

        app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor'], engineeringProgress: $progress);

        return $measurement->fresh();
    }

    /**
     * Aprovada pela Engenharia, pela Gestão e pela Compliance, parada na etapa
     * Pagamento.
     *
     * @param  array{actor: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}  $scenario
     * @param  list<int|float|string>  $percents
     */
    public static function awaitingPayment(array $scenario, string $month, array $percents): Measurement
    {
        $measurement = self::measured($scenario, $month, $percents);
        $workflow = app(MeasurementWorkflow::class);
        $workflow->approve($measurement->fresh(), $scenario['actor']);
        $workflow->approve($measurement->fresh(), $scenario['actor']);

        return $measurement->fresh();
    }

    /**
     * A decisão expressa de concluir a etapa Pagamento sem desembolso.
     *
     * @param  array{actor: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}  $scenario
     */
    public static function concludeWithoutDisbursement(array $scenario, Measurement $measurement, ?string $justification = self::JUSTIFICATION, ?User $actor = null): Measurement
    {
        app(MeasurementWorkflow::class)->approve(
            $measurement->fresh(),
            $actor ?? $scenario['actor'],
            $justification,
            expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
            withoutDisbursement: true,
        );

        return $measurement->fresh();
    }

    /**
     * A Finalização com a ciência da ausência de pagamento.
     *
     * @param  array{actor: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}  $scenario
     */
    public static function finalizeAcknowledging(array $scenario, Measurement $measurement, ?User $actor = null): Measurement
    {
        app(MeasurementWorkflow::class)->finalize(
            $measurement->fresh(),
            $actor ?? $scenario['finalizer'] ?? $scenario['actor'],
            acknowledgeNoDisbursement: true,
        );

        return $measurement->fresh();
    }
}
