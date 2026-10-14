<?php

namespace Tests\Support;

use App\Enums\MeasurementReceiptReviewStatus;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\User;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * As ações das corridas da revisão de medição (Fase 3.1) num processo próprio
 * do grupo `mysql`: criar, enviar, recusar e aprovar a revisão; aprovar a
 * etapa e finalizar a vigente; enviar e conferir comprovante.
 *
 * Classe, e não função do arquivo de teste: o processo filho de
 * `Concurrency::driver('process')` só carrega o autoload (`App\`, `Tests\`).
 *
 * Cada caminho trava primeiro uma tabela, e é nela que a corrida acontece:
 * a Operation, para quem a trava antes de tudo (criar, enviar, recusar e
 * aprovar a revisão na Engenharia e na Compliance, registrar pagamento,
 * finalizar); a linha da medição, para quem só trava a medição (devolução da
 * Finalização, decisões das etapas 2 a 4 da vigente, comprovantes, reavaliação
 * de pagamento). Quem trava a Operation trava a medição logo depois, então a
 * corrida entre um caminho de cada tipo acontece na linha da medição.
 *
 * Quem recebe `lock_marker` segura por 750 ms o primeiro lock que pedir em
 * `lock_table` e só então segue; quem recebe `wait_for_marker` só começa
 * depois que o outro o tem. `lock_wait_ms` é quanto o primeiro lock da própria
 * transação em `lock_table` esperou.
 */
final class MeasurementRevisionRaceTasks
{
    public const OPERATIONS = 'operations';

    public const MEASUREMENTS = 'measurements';

    private const HOLD_MICROSECONDS = 750_000;

    private const MARKER_TIMEOUT_SECONDS = 10;

    /**
     * @param  array{action: string, actor_id: int, storage_root: string, lock_table: string, lock_marker?: string, wait_for_marker?: string, measurement_id?: int, payment_id?: int, evidence_id?: int|null, stage?: int, revision?: int, status?: string, notes?: string|null}  $instruction
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
     * A ação, pelo mesmo serviço que a tela chama, com o estado esperado que
     * a tela teria lido antes.
     *
     * @param  array<string, mixed>  $instruction
     */
    private static function perform(array $instruction, User $actor): mixed
    {
        return match ($instruction['action']) {
            'create_revision' => app(MeasurementRevisionService::class)
                ->create(self::measurement($instruction), $actor, 'Correção concorrente.')
                ->getKey(),
            'submit_revision' => app(MeasurementRevisionService::class)->submit(self::measurement($instruction), $actor),
            'approve' => app(MeasurementWorkflow::class)->approve(
                self::measurement($instruction),
                $actor,
                $instruction['notes'] ?? null,
                expectedStage: $instruction['stage'],
                expectedRevision: $instruction['revision'],
            ),
            'reject' => app(MeasurementWorkflow::class)->reject(
                self::measurement($instruction),
                $actor,
                'Recusa concorrente.',
                expectedStage: $instruction['stage'],
                expectedRevision: $instruction['revision'],
            ),
            'finalize' => app(MeasurementWorkflow::class)->finalize(
                self::measurement($instruction),
                $actor,
                expectedRevision: $instruction['revision'],
                expectedStatus: $instruction['status'],
            ),
            'upload_receipt' => app(MeasurementReceiptEvidenceService::class)->upload(
                MeasurementPayment::query()->findOrFail($instruction['payment_id']),
                $actor,
                MeasurementReceiptEvidenceScenario::file('comprovante-concorrente.pdf', '%PDF-1.7 comprovante concorrente'),
                $instruction['evidence_id'],
                'Correção concorrente do comprovante.',
            )->getKey(),
            'approve_receipt' => app(MeasurementReceiptEvidenceService::class)->review(
                MeasurementPaymentReceiptEvidence::query()->findOrFail($instruction['evidence_id']),
                $actor,
                MeasurementReceiptReviewStatus::Approved,
                true,
            )->getKey(),
            default => throw new RuntimeException("Ação de corrida desconhecida: {$instruction['action']}."),
        };
    }

    /**
     * @param  array<string, mixed>  $instruction
     */
    private static function measurement(array $instruction): Measurement
    {
        return Measurement::query()->findOrFail($instruction['measurement_id']);
    }
}
