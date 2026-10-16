<?php

namespace Tests\Support;

use App\Enums\MeasurementReceiptReviewStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Operação com um usuário por papel do fluxo -- coordenação, Engenharia,
 * Gestão, Compliance, Gestor de Pagamento, quem envia comprovante e
 * Finalizador --, quem cadastra regra financeira e os administradores, e
 * empreendimentos de obras da própria emissão da operação, cada um com o
 * próprio Fundo de Obra (positivo, zero ou nulo).
 *
 * É o cenário da governança financeira e da segregação de funções: cada ato
 * é feito por quem responde por ele, e quem confere é outra pessoa -- a menos
 * que o teste escolha o contrário passando o usuário. Classe, e não função de
 * arquivo de teste, para servir também aos processos filhos do grupo `mysql`.
 * O disco `local` precisa estar preparado por quem chama (`Storage::fake()`),
 * e as notificações, falsas.
 */
final class MeasurementGovernanceScenario
{
    public const PAY_DATE = '2026-06-10';

    public const JUSTIFICATION = 'Divergência prevista nas condições contratuais da emissão.';

    /**
     * @param  list<array{fund: string|null, name?: string}>  $developments
     * @param  list<string>  $months  meses do cronograma ('Y-m')
     * @return array<string, mixed>
     */
    public static function plan(
        array $developments = [['fund' => '1000000.00', 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']],
        array $months = ['2026-05', '2026-06', '2026-07'],
    ): array {
        config()->set('filesystems.private_disk', 'local');

        $users = [
            'coordinator' => self::user(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.revise']),
            'engineer' => self::user(['measurements.view', 'measurements.review']),
            'manager' => self::user(['measurements.view', 'measurements.review']),
            'compliance' => self::user(['measurements.view', 'measurements.review']),
            'payer' => self::user(['measurements.view', 'measurements.pay']),
            'uploader' => self::user(['measurements.view', 'measurements.receipts']),
            'finalizer' => self::user(['measurements.view', 'measurements.finalize']),
            'ruleAuthor' => self::user(['measurements.financial-rules.view', 'measurements.financial-rules.manage']),
        ];

        $admin = User::factory()->withTwoFactor()->create();
        $admin->assignRole('admin');
        $superAdmin = User::factory()->withTwoFactor()->create();
        $superAdmin->assignRole('super-admin');

        $emission = Emission::factory()->create();
        $operation = Operation::factory()->forEmission($emission)->create([
            'status' => 'active',
            'assigned_user_id' => $users['coordinator']->id,
            'responsible_user_id' => $users['engineer']->id,
            'stage2_reviewer_user_id' => $users['manager']->id,
            'stage3_reviewer_user_id' => $users['compliance']->id,
            'payment_manager_user_id' => $users['payer']->id,
            'payment_receipt_uploader_user_id' => $users['uploader']->id,
            'payment_finalizer_user_id' => $users['finalizer']->id,
        ]);

        $planSets = [];
        $constructions = [];
        $lines = [];

        foreach (array_values($developments) as $index => $development) {
            $construction = Construction::factory()->create([
                'emission_id' => $emission->id,
                'development_name' => $development['name'] ?? 'Torre '.chr(65 + $index),
            ]);
            $planSet = MeasurementPlanSet::factory()->withConstructionFund($development['fund'])->create([
                'operation_id' => $operation->id,
                'construction_id' => $construction->id,
                'name' => 'Plano '.($index + 1),
                'is_default' => $index === 0,
                'initial_incurred_amount' => '0.00',
                'initial_physical_progress_percent' => '0.00',
                'initial_physical_progress_reference_date' => null,
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

            MeasurementPlanVersionFixture::activate($planSet);

            $constructions[] = $construction;
            $planSets[] = $planSet;
            $lines[] = $planLines;
        }

        return [
            ...$users,
            'admin' => $admin,
            'superAdmin' => $superAdmin,
            'emission' => $emission,
            'operation' => $operation->fresh(),
            'constructions' => $constructions,
            'planSets' => $planSets,
            'lines' => $lines,
        ];
    }

    /**
     * Valor aprovado pela Engenharia para o percentual e o Fundo de Obra.
     */
    public static function expected(string $fund, int|float|string $percent): string
    {
        return bcdiv(bcmul($fund, (string) $percent, 6), '100', 2);
    }

    /**
     * Medição original enviada pela coordenação e aprovada pela Engenharia,
     * com o realizado do mês de cada empreendimento.
     *
     * @param  array<string, mixed>  $scenario
     * @param  list<int|float|string>  $percents
     */
    public static function measured(array $scenario, string $month, array $percents): Measurement
    {
        $measurement = Measurement::factory()->create([
            'operation_id' => $scenario['operation']->id,
            'reference_month' => $month.'-01',
            'status' => 'pending',
            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
            'storage_path' => null,
            'filename' => null,
            'uploaded_by' => $scenario['coordinator']->id,
        ]);

        foreach ($scenario['planSets'] as $index => $planSet) {
            $path = "nimbus_docs/measurements/assets/governance-{$measurement->id}-{$index}.pdf";
            Storage::disk('local')->put($path, "%PDF-1.7 governanca {$measurement->id} {$index}");
            $measurement->assets()->create([
                'plan_set_id' => $planSet->id,
                'plan_line_id' => $scenario['lines'][$index][$month]->id,
                'storage_path' => $path,
                'storage_disk' => 'local',
            ]);
        }

        $workflow = app(MeasurementWorkflow::class);
        $workflow->startReview($measurement, $scenario['coordinator']);
        $workflow->approve($measurement->fresh(), $scenario['engineer'], engineeringProgress: self::progress($scenario, $percents));

        return $measurement->fresh();
    }

    /**
     * Aprovada pela Engenharia, pela Gestão e pela Compliance, parada na etapa
     * Pagamento.
     *
     * @param  array<string, mixed>  $scenario
     * @param  list<int|float|string>  $percents
     */
    public static function awaitingPayment(array $scenario, string $month, array $percents): Measurement
    {
        $measurement = self::measured($scenario, $month, $percents);
        $workflow = app(MeasurementWorkflow::class);
        $workflow->approve($measurement->fresh(), $scenario['manager']);
        $workflow->approve($measurement->fresh(), $scenario['compliance']);

        return $measurement->fresh();
    }

    /**
     * Uma linha de pagamento para o empreendimento de índice `$plan`.
     *
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function row(array $scenario, int $plan, string $amount, array $extra = []): array
    {
        return [
            'plan_set_id' => $scenario['planSets'][$plan]->id,
            'pay_date' => self::PAY_DATE,
            'amount' => $amount,
            'method' => 'TED',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, MeasurementPayment>
     */
    public static function pay(array $scenario, Measurement $measurement, array $rows, ?User $by = null, ?string $overrideReason = null): Collection
    {
        return app(MeasurementWorkflow::class)->registerPayments(
            $measurement->fresh(),
            $by ?? $scenario['payer'],
            array_map(fn (array $row): array => MeasurementReceiptEvidenceScenario::withReceipt($row), $rows),
            overrideReason: $overrideReason,
        );
    }

    /**
     * Regra financeira da emissão da operação, registrada por quem cadastra
     * regras (não por quem paga), vigente em 2026 sem fim definido.
     *
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $attributes
     */
    public static function rule(array $scenario, array $attributes = [], ?User $author = null): MeasurementFinancialRule
    {
        return MeasurementFinancialRule::factory()->create([
            'emission_id' => $scenario['emission']->id,
            'construction_id' => null,
            'name' => 'Tolerância contratual',
            'direction' => 'over',
            'maximum_difference_amount' => '5000.00',
            'maximum_difference_percent' => null,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'created_by' => ($author ?? $scenario['ruleAuthor'])->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public static function approvePaymentStage(array $scenario, Measurement $measurement, ?string $notes = null, ?User $by = null): Measurement
    {
        app(MeasurementWorkflow::class)->approve($measurement->fresh(), $by ?? $scenario['payer'], $notes, expectedStage: MeasurementWorkflow::STAGE_PAYMENT);

        return $measurement->fresh();
    }

    /**
     * Envia o comprovante de cada pagamento que ainda não tem.
     *
     * @param  array<string, mixed>  $scenario
     */
    public static function uploadReceipts(array $scenario, Measurement $measurement, ?User $by = null, ?string $overrideReason = null): void
    {
        foreach ($measurement->fresh()->payments()->orderBy('id')->get() as $payment) {
            if ($payment->currentReceiptEvidence()->exists()) {
                continue;
            }

            app(MeasurementReceiptEvidenceService::class)->upload(
                $payment->fresh(),
                $by ?? $scenario['uploader'],
                MeasurementReceiptEvidenceScenario::file("comprovante-{$payment->id}.pdf", "%PDF-1.7 comprovante {$payment->id}"),
                overrideReason: $overrideReason,
            );
        }
    }

    /**
     * Aprova a versão pendente de cada comprovante.
     *
     * @param  array<string, mixed>  $scenario
     */
    public static function approveReceipts(array $scenario, Measurement $measurement, ?User $by = null, ?string $overrideReason = null): void
    {
        foreach ($measurement->fresh()->payments()->orderBy('id')->get() as $payment) {
            $evidence = $payment->currentReceiptEvidence()->first();

            if (! $evidence instanceof MeasurementPaymentReceiptEvidence || $evidence->review_status !== MeasurementReceiptReviewStatus::Pending) {
                continue;
            }

            app(MeasurementReceiptEvidenceService::class)->review(
                $evidence,
                $by ?? $scenario['finalizer'],
                MeasurementReceiptReviewStatus::Approved,
                true,
                overrideReason: $overrideReason,
            );
        }
    }

    /**
     * Aprova a etapa Pagamento e conclui a conferência dos comprovantes: a
     * medição fica pronta para a Finalização.
     *
     * @param  array<string, mixed>  $scenario
     */
    public static function readyToFinalize(array $scenario, Measurement $measurement, ?string $paymentNotes = null): Measurement
    {
        self::approvePaymentStage($scenario, $measurement, $paymentNotes);
        self::uploadReceipts($scenario, $measurement);
        self::approveReceipts($scenario, $measurement);

        return $measurement->fresh();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public static function finalize(array $scenario, Measurement $measurement, bool $accept = false, ?User $by = null, bool $acknowledgeNoDisbursement = false, ?string $overrideReason = null): Measurement
    {
        app(MeasurementWorkflow::class)->finalize(
            $measurement->fresh(),
            $by ?? $scenario['finalizer'],
            acceptFinancialExceptions: $accept,
            acknowledgeNoDisbursement: $acknowledgeNoDisbursement,
            overrideReason: $overrideReason,
        );

        return $measurement->fresh();
    }

    /**
     * Revisão (R1, R2...) criada como rascunho pela coordenação.
     *
     * @param  array<string, mixed>  $scenario
     */
    public static function revise(array $scenario, Measurement $base, ?User $by = null, string $reason = 'Correção do percentual medido.'): Measurement
    {
        return app(MeasurementRevisionService::class)->create($base->fresh(), $by ?? $scenario['coordinator'], $reason);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public static function submit(array $scenario, Measurement $draft, ?User $by = null, ?string $overrideReason = null): Measurement
    {
        app(MeasurementRevisionService::class)->submit($draft->fresh(), $by ?? $scenario['coordinator'], overrideReason: $overrideReason);

        return $draft->fresh();
    }

    /**
     * Revisão enviada e aprovada pela Engenharia (com os novos percentuais) e
     * pela Gestão, parada na Compliance.
     *
     * @param  array<string, mixed>  $scenario
     * @param  list<int|float|string>  $percents
     */
    public static function revisionAtCompliance(array $scenario, Measurement $base, array $percents, ?User $creator = null, ?User $submitter = null, ?string $overrideReason = null): Measurement
    {
        $draft = self::revise($scenario, $base, $creator);
        $revision = self::submit($scenario, $draft, $submitter ?? $creator, $overrideReason);
        $workflow = app(MeasurementWorkflow::class);
        $workflow->approve($revision->fresh(), $scenario['engineer'], engineeringProgress: self::progress($scenario, $percents));
        $workflow->approve($revision->fresh(), $scenario['manager']);

        return $revision->fresh();
    }

    /**
     * Medição finalizada pelo fluxo real, paga pelo valor aprovado de cada
     * empreendimento.
     *
     * @param  array<string, mixed>  $scenario
     * @param  list<int|float|string>  $percents
     */
    public static function finalized(array $scenario, string $month, array $percents): Measurement
    {
        $measurement = self::awaitingPayment($scenario, $month, $percents);
        $rows = [];

        foreach ($scenario['planSets'] as $index => $planSet) {
            $fund = (string) $planSet->fresh()->currentConstructionFundAmount();
            $amount = self::expected($fund, $percents[$index]);

            if (bccomp($amount, '0', 2) > 0) {
                $rows[] = self::row($scenario, $index, $amount);
            }
        }

        if ($rows === []) {
            app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['payer'], 'Sem valor aprovado nesta competência.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true);

            return self::finalize($scenario, $measurement, acknowledgeNoDisbursement: true);
        }

        self::pay($scenario, $measurement, $rows);
        self::readyToFinalize($scenario, $measurement);

        return self::finalize($scenario, $measurement);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  list<int|float|string>  $percents
     * @return array<int, int|float|string>
     */
    private static function progress(array $scenario, array $percents): array
    {
        $progress = [];

        foreach ($scenario['planSets'] as $index => $planSet) {
            $progress[$planSet->id] = $percents[$index] ?? 0;
        }

        return $progress;
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function user(array $permissions): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
