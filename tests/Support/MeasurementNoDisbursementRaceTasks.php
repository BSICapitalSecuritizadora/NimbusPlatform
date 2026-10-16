<?php

namespace Tests\Support;

use App\Enums\MeasurementReceiptReviewStatus;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\User;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * As ações das corridas da conclusão sem desembolso (Fase 4A) num processo
 * próprio do grupo `mysql`: a decisão de concluir a etapa Pagamento sem
 * desembolso, o registro de pagamento, a pausa da etapa Pagamento, a
 * Finalização com a ciência da ausência de pagamento e a devolução da
 * Finalização à etapa Pagamento -- pelo mesmo serviço que a tela chama, com o
 * estado esperado que a tela teria lido antes. Da Fase 4B-1: a reavaliação do
 * enquadramento de um pagamento, a aprovação de uma etapa e a aprovação de um
 * comprovante, cada uma pelo `actor_id` da própria instrução.
 *
 * Classe, e não função do arquivo de teste: o processo filho de
 * `Concurrency::driver('process')` só carrega o autoload (`App\`, `Tests\`).
 *
 * A decisão, a pausa e a devolução travam só a linha da medição; o registro de
 * pagamento e a Finalização travam a Operation e logo depois a medição. Entre
 * duas delas a corrida acontece na linha da medição, e é nela que fica o
 * marcador.
 *
 * Quem recebe `lock_marker` segura por 750 ms o primeiro lock que pedir em
 * `lock_table` e só então segue; quem recebe `wait_for_marker` só começa
 * depois que o outro o tem. `lock_wait_ms` é quanto o primeiro lock da própria
 * transação em `lock_table` esperou.
 */
final class MeasurementNoDisbursementRaceTasks
{
    public const MEASUREMENTS = 'measurements';

    /**
     * Dois registros de pagamento (e a aprovação que trava a família de uma
     * revisão) disputam a Operation, que travam primeiro.
     */
    public const OPERATIONS = 'operations';

    private const HOLD_MICROSECONDS = 750_000;

    private const MARKER_TIMEOUT_SECONDS = 10;

    /**
     * @param  array{action: string, actor_id: int, measurement_id: int, revision: int, storage_root: string, lock_table: string, lock_marker?: string, wait_for_marker?: string, stage?: int, status?: string, notes?: string, payment?: array<string, int|string>, payment_id?: int, evidence_id?: int, override_reason?: string}  $instruction
     * @return array{success: bool, result: int|null, exception: class-string<Throwable>|null, message: string|null, lock_wait_ms: float|null}
     */
    public static function run(array $instruction): array
    {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();

        $lock = ['wait_ms' => null];
        $table = '`'.$instruction['lock_table'].'`';

        DB::listen(static function (QueryExecuted $query) use ($instruction, $table, &$lock): void {
            $sql = strtolower($query->sql);

            if ($lock['wait_ms'] !== null || ! str_contains($sql, $table) || ! str_contains($sql, 'for update')) {
                return;
            }

            $lock['wait_ms'] = $query->time;

            if (isset($instruction['lock_marker'])) {
                file_put_contents($instruction['lock_marker'], 'locked');
                usleep(self::HOLD_MICROSECONDS);
            }
        });

        try {
            self::waitForTheOtherLock($instruction);

            $result = self::perform($instruction, User::query()->findOrFail($instruction['actor_id']));

            return ['success' => true, 'result' => is_int($result) ? $result : null, 'exception' => null, 'message' => null, 'lock_wait_ms' => $lock['wait_ms']];
        } catch (ValidationException $exception) {
            return ['success' => false, 'result' => null, 'exception' => $exception::class, 'message' => collect($exception->errors())->flatten()->implode(' '), 'lock_wait_ms' => $lock['wait_ms']];
        } catch (Throwable $exception) {
            return ['success' => false, 'result' => null, 'exception' => $exception::class, 'message' => $exception->getMessage(), 'lock_wait_ms' => $lock['wait_ms']];
        }
    }

    /**
     * @param  array<string, mixed>  $instruction
     */
    private static function waitForTheOtherLock(array $instruction): void
    {
        if (! isset($instruction['wait_for_marker'])) {
            return;
        }

        $deadline = microtime(true) + self::MARKER_TIMEOUT_SECONDS;

        while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! is_file($instruction['wait_for_marker'])) {
            throw new RuntimeException("O processo concorrente não confirmou o lock em {$instruction['lock_table']}.");
        }
    }

    /**
     * A ação, com os argumentos que a página de Visualizar Medição passa ao
     * serviço: a decisão com a etapa Pagamento esperada, a pausa com a etapa
     * lida, a Finalização e a devolução com a situação lida -- e todas com a
     * versão do fluxo que a tela leu.
     *
     * @param  array<string, mixed>  $instruction
     */
    private static function perform(array $instruction, User $actor): mixed
    {
        $workflow = app(MeasurementWorkflow::class);
        $measurement = Measurement::query()->findOrFail($instruction['measurement_id']);

        return match ($instruction['action']) {
            'conclude_without_disbursement' => $workflow->approve(
                $measurement,
                $actor,
                $instruction['notes'],
                expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
                expectedRevision: $instruction['revision'],
                withoutDisbursement: true,
            ),
            'register_payment' => $workflow->registerPayments(
                $measurement,
                $actor,
                [$instruction['payment']],
                expectedRevision: $instruction['revision'],
            )->sole()->getKey(),
            'pause' => $workflow->pause(
                $measurement,
                $actor,
                $instruction['notes'],
                expectedStage: $instruction['stage'],
                expectedRevision: $instruction['revision'],
            ),
            'finalize_acknowledging' => $workflow->finalize(
                $measurement,
                $actor,
                expectedRevision: $instruction['revision'],
                expectedStatus: $instruction['status'],
                acknowledgeNoDisbursement: true,
            ),
            'return_to_payment' => $workflow->returnToStage(
                $measurement,
                $actor,
                MeasurementWorkflow::STAGE_PAYMENT,
                $instruction['notes'],
                expectedRevision: $instruction['revision'],
                expectedStatus: $instruction['status'],
            ),
            // Fase 4B-1: a reavaliação do enquadramento, a aprovação de uma
            // etapa (a Compliance da revisão) e a conferência do comprovante.
            'reassess_payment' => $workflow->reassessPayment(
                MeasurementPayment::query()->findOrFail($instruction['payment_id']),
                $actor,
                $instruction['payment'] ?? [],
                $instruction['revision'],
            ),
            'approve_stage' => $workflow->approve(
                $measurement,
                $actor,
                expectedStage: $instruction['stage'],
                expectedRevision: $instruction['revision'],
                overrideReason: $instruction['override_reason'] ?? null,
            ),
            'approve_receipt' => app(MeasurementReceiptEvidenceService::class)->review(
                MeasurementPaymentReceiptEvidence::query()->findOrFail($instruction['evidence_id']),
                $actor,
                MeasurementReceiptReviewStatus::Approved,
                true,
                overrideReason: $instruction['override_reason'] ?? null,
            )->getKey(),
            default => throw new RuntimeException("Ação de corrida desconhecida: {$instruction['action']}."),
        };
    }
}
