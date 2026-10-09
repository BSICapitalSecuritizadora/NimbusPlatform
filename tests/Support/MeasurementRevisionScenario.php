<?php

namespace Tests\Support;

use App\Enums\AccessPermission;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Medições que passam pelo fluxo real até a etapa Pagamento ou a Finalização,
 * e revisões delas (R1, R2...) criadas, enviadas e aprovadas pelo serviço de
 * revisões -- o mesmo caminho que trava, confere e audita.
 *
 * Classe, e não função de arquivo de teste, para servir também aos processos
 * filhos do grupo `mysql`. O disco `local` precisa estar preparado por quem
 * chama, e as notificações, falsas.
 */
final class MeasurementRevisionScenario
{
    public const FUND = '1000000.00';

    /**
     * @param  list<string>  $months
     * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}
     */
    public static function plan(array $months = ['2026-05', '2026-06', '2026-07'], string $initialPercent = '0.00', ?string $referenceDate = null): array
    {
        $scenario = MeasurementPhysicalProgressScenario::plan($months, $initialPercent, $referenceDate);
        $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsRevise->value);

        return $scenario;
    }

    /**
     * Valor aprovado pela Engenharia para o percentual do mês, no Fundo de Obra
     * do cenário.
     */
    public static function expected(int|float|string $percent, string $fund = self::FUND): string
    {
        return bcdiv(bcmul($fund, (string) $percent, 6), '100', 2);
    }

    /**
     * Medição aprovada pela Engenharia, pela Gestão e pela Compliance, parada
     * na etapa Pagamento.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function awaitingPayment(array $scenario, string $month, int|float|string $percent): Measurement
    {
        $measurement = MeasurementPhysicalProgressScenario::measured($scenario, $month, $percent);
        self::approveManagementAndCompliance($scenario, $measurement);

        return $measurement->fresh();
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function approveManagementAndCompliance(array $scenario, Measurement $measurement): void
    {
        $workflow = app(MeasurementWorkflow::class);
        $workflow->approve($measurement->fresh(), $scenario['actor']);
        $workflow->approve($measurement->fresh(), $scenario['actor']);
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function pay(array $scenario, Measurement $measurement, string $amount, ?int $financialRuleId = null, ?string $justification = null): MeasurementPayment
    {
        return app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $scenario['actor'], array_filter([
            'plan_set_id' => $scenario['planSet']->id,
            'pay_date' => '2026-07-20',
            'amount' => $amount,
            'method' => 'TED',
            'financial_rule_id' => $financialRuleId,
            'financial_justification' => $justification,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Aprova a etapa Pagamento, envia e aprova os comprovantes que faltam e
     * finaliza.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function closePaymentAndFinalize(array $scenario, Measurement $measurement, ?string $paymentNotes = null, bool $acceptFinancialExceptions = false): Measurement
    {
        $workflow = app(MeasurementWorkflow::class);
        $workflow->approve($measurement->fresh(), $scenario['actor'], $paymentNotes);
        self::receiptsApproved($scenario, $measurement);
        $workflow->finalize($measurement->fresh(), $scenario['actor'], acceptFinancialExceptions: $acceptFinancialExceptions);

        return $measurement->fresh();
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function receiptsApproved(array $scenario, Measurement $measurement): void
    {
        $workflow = app(MeasurementWorkflow::class);

        foreach ($measurement->fresh()->payments()->orderBy('id')->get() as $payment) {
            if ($payment->currentReceiptEvidence()->exists()) {
                continue;
            }

            $workflow->attachReceipt($payment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file(
                "comprovante-{$payment->id}.pdf",
                "%PDF-1.7 comprovante {$payment->id}",
            ));
            MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);
        }
    }

    /**
     * Medição finalizada pelo fluxo real, paga pelo valor informado (o
     * esperado, sem valor).
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function finalized(array $scenario, string $month, int|float|string $percent, ?string $paidAmount = null): Measurement
    {
        $measurement = self::awaitingPayment($scenario, $month, $percent);
        self::pay($scenario, $measurement, $paidAmount ?? self::expected($percent));

        return self::closePaymentAndFinalize($scenario, $measurement);
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function revise(array $scenario, Measurement $base, string $reason = 'Correção do percentual medido.'): Measurement
    {
        return app(MeasurementRevisionService::class)->create($base->fresh(), $scenario['actor'], $reason);
    }

    /**
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function submit(array $scenario, Measurement $draft): Measurement
    {
        app(MeasurementRevisionService::class)->submit($draft->fresh(), $scenario['actor']);

        return $draft->fresh();
    }

    /**
     * Revisão enviada e aprovada pela Engenharia com o percentual corrigido.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function engineeringApproved(array $scenario, Measurement $base, int|float|string $percent, string $reason = 'Correção do percentual medido.'): Measurement
    {
        $revision = self::submit($scenario, self::revise($scenario, $base, $reason));
        MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, $percent);

        return $revision->fresh();
    }

    /**
     * O envio de uma medição nova como a página de envio o faz: a Operation
     * travada primeiro, depois a medição e o arquivo, que ocupa a medição
     * prevista escolhida. Para os processos filhos do grupo `mysql`.
     *
     * @param  array{operation_id: int, reference_month: string, plan_set_id: int, plan_line_id: int}  $submission
     */
    public static function submitUnderOperationLock(array $submission, User $actor): int
    {
        return DB::transaction(function () use ($submission, $actor): int {
            Operation::query()->whereKey($submission['operation_id'])->lockForUpdate()->firstOrFail();

            $measurement = Measurement::query()->create([
                'operation_id' => $submission['operation_id'],
                'reference_month' => $submission['reference_month'],
                'status' => 'pending',
                'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
                'uploaded_by' => $actor->getKey(),
                'uploaded_at' => now(),
            ]);
            $path = "nimbus_docs/measurements/assets/race-{$measurement->getKey()}.pdf";
            Storage::disk('local')->put($path, "%PDF-1.7 envio concorrente {$measurement->getKey()}");
            $measurement->assets()->create([
                'plan_set_id' => $submission['plan_set_id'],
                'plan_line_id' => $submission['plan_line_id'],
                'storage_path' => $path,
                'storage_disk' => 'local',
            ]);

            return (int) $measurement->getKey();
        });
    }

    /**
     * Revisão criada, enviada e aprovada pela Engenharia, pela Gestão e pela
     * Compliance: a vigente da medição lógica, parada na etapa Pagamento.
     *
     * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
     */
    public static function effective(array $scenario, Measurement $base, int|float|string $percent, string $reason = 'Correção do percentual medido.'): Measurement
    {
        $revision = self::engineeringApproved($scenario, $base, $percent, $reason);
        self::approveManagementAndCompliance($scenario, $revision);

        return $revision->fresh();
    }
}
