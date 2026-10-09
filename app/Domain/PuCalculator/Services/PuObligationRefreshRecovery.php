<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\DTOs\PuObligationRefreshResult;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\PuObligationRefreshRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Atualização DURÁVEL das obrigações financeiras do PU (Fase 6, dívida D5).
 *
 * Antes, a atualização pedida depois do commit era só um callback em memória: se
 * ela falhasse, ficava reportada; se o processo morresse entre o commit e o
 * callback, o pedido sumia, e só um evento futuro qualquer ou o
 * `pu:obligations:reconcile` manual recompunham as obrigações.
 *
 * Agora o pedido é uma linha gravada NA MESMA transação do fato que o provoca
 * ({@see self::request()}): se o fato comitou, o pedido existe. Quem pede só
 * insere -- pedidos nunca disputam trava entre si. A execução
 * ({@see self::process()}):
 *
 *  1. reserva, numa transação curta, os pedidos elegíveis da emissão (token e
 *     prazo de concessão). Dois executores nunca reservam o mesmo pedido; o
 *     que encontra a reserva viva de outro não faz nada;
 *  2. recompõe as obrigações com {@see PuFinancialObligationService::refresh()},
 *     que relê o estado governado VIGENTE -- curva oficial, eventos, cronograma
 *     informado, livro de liquidações. O pedido não carrega o que calcular, então
 *     uma execução atrasada nunca repete um esperado obsoleto: se outra versão já
 *     foi homologada, é dela que o esperado vem; se nada mudou, nada é escrito;
 *  3. registra o resultado numa transação curta. Sucesso encerra os reservados e
 *     cobre os outros pedidos abertos que já eram visíveis na reserva (os fatos
 *     deles comitaram antes, e a recomposição os viu). Falha passageira agenda
 *     nova tentativa com espera crescente; esgotada a tentativa, ou falha
 *     permanente (domínio, governança, efeito sem regra), o pedido fica parado
 *     e visível até uma retomada autorizada ({@see self::retry()}).
 *
 * Nenhuma trava de pedido é segurada enquanto a emissão é travada: a reserva e o
 * registro são transações próprias, então a ordem emissão → obrigações da Fase 5
 * fica intacta. Um processo que morre no meio deixa o pedido "em execução" com a
 * concessão vencendo; a varredura ({@see self::recoverDue()}) o retoma como
 * tentativa interrompida.
 *
 * A recomposição é idempotente (identidade econômica UNIQUE, cálculo vigente
 * único, liquidação nunca escrita aqui): repetir converge para o mesmo estado.
 */
class PuObligationRefreshRecovery
{
    public const VIA_AFTER_COMMIT = 'after_commit';

    public const VIA_SCANNER = 'scanner';

    public const VIA_MANUAL = 'manual';

    public function __construct(
        private readonly PuOperationalFailureClassifier $failures,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * Grava o pedido na transação corrente (ou já, fora de transação). A
     * varredura só o pega depois da carência: até lá, quem pediu tenta logo
     * depois do commit.
     */
    public function request(int $emissionId, string $trigger, ?int $requestedBy = null): PuObligationRefreshRequest
    {
        $now = CarbonImmutable::now();

        return PuObligationRefreshRequest::query()->create([
            'emission_id' => $emissionId,
            'trigger' => Str::limit($trigger, 60, ''),
            'status' => PuObligationRefreshStatus::Pending,
            'correlation_id' => (string) Str::uuid(),
            'requested_by' => $requestedBy,
            'requested_at' => $now,
            'attempts' => 0,
            'next_attempt_at' => $now->addSeconds($this->scannerGraceSeconds()),
        ]);
    }

    /**
     * Executa os pedidos elegíveis de uma emissão. Nunca lança: a falha fica no
     * pedido (e no log), não em quem chamou -- o fato que provocou o pedido já
     * comitou e não é desfeito por ela.
     */
    public function process(int $emissionId, string $via, ?int $actorId = null): PuObligationRefreshOutcome
    {
        try {
            $claim = $this->claim($emissionId, $via, CarbonImmutable::now());
        } catch (Throwable $exception) {
            report($exception);

            return new PuObligationRefreshOutcome(
                emissionId: $emissionId,
                via: $via,
                status: PuObligationRefreshOutcome::NOTHING_TO_DO,
                failureCategory: $this->failures->classify($exception),
                message: $this->failures->sanitize($exception->getMessage()),
            );
        }

        if ($claim === null) {
            return new PuObligationRefreshOutcome($emissionId, $via, PuObligationRefreshOutcome::NOTHING_TO_DO);
        }

        try {
            $emission = Emission::query()->find($emissionId);
            $result = $emission instanceof Emission
                ? app(PuFinancialObligationService::class)->refresh($emission, $claim['trigger'], $actorId)
                : new PuObligationRefreshResult(null);
        } catch (Throwable $exception) {
            report($exception);

            return $this->recordFailure($emissionId, $via, $claim, $exception);
        }

        return $this->recordSuccess($emissionId, $via, $claim, $result, $emission instanceof Emission);
    }

    /**
     * Varredura: emissões com pedido vencido (pendente depois da carência, nova
     * tentativa agendada que chegou, execução interrompida). Cada emissão é
     * processada uma vez por rodada.
     *
     * @return list<PuObligationRefreshOutcome>
     */
    public function recoverDue(?CarbonInterface $now = null, ?int $limit = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $emissionIds = PuObligationRefreshRequest::query()
            ->where(function ($query) use ($now): void {
                $query
                    ->where(fn ($due) => $due
                        ->whereIn('status', [PuObligationRefreshStatus::Pending->value, PuObligationRefreshStatus::RetryScheduled->value])
                        ->where('next_attempt_at', '<=', $now))
                    ->orWhere(fn ($interrupted) => $interrupted
                        ->where('status', PuObligationRefreshStatus::Running->value)
                        ->where('claim_expires_at', '<', $now));
            })
            ->orderBy('id')
            ->limit($limit ?? (int) config('pu_calculator.obligation_refresh.scanner_batch_size', 50))
            ->pluck('emission_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return array_map(fn (int $emissionId): PuObligationRefreshOutcome => $this->process($emissionId, self::VIA_SCANNER), $emissionIds);
    }

    /**
     * Pedido atendido é diagnóstico: depois do prazo configurado, sai. Pedido
     * aberto (pendente, em nova tentativa, esgotado, bloqueado) nunca é apagado.
     */
    public function pruneCompleted(?CarbonInterface $now = null): int
    {
        $days = max(1, (int) config('pu_calculator.obligation_refresh.completed_retention_days', 90));
        $cutoff = CarbonImmutable::instance($now ?? now())->subDays($days);

        return PuObligationRefreshRequest::query()
            ->whereIn('status', [PuObligationRefreshStatus::Succeeded->value, PuObligationRefreshStatus::Superseded->value])
            ->where('completed_at', '<', $cutoff)
            ->limit(1000)
            ->delete();
    }

    /**
     * Retomada autorizada: os pedidos esgotados ou bloqueados da emissão voltam a
     * pendentes e são executados na hora. Sem pedido parado, registra um pedido
     * manual novo. Exige `pu.operations.recover` e motivo; fica na trilha
     * protegida. Não homologa, não liquida e não decide conflito: só recompõe o
     * que é derivado dos fatos.
     *
     * @throws AuthorizationException
     */
    public function retry(int $emissionId, User $actor, string $reason): PuObligationRefreshOutcome
    {
        if (! $actor->can(AccessPermission::PuOperationsRecover->value)) {
            throw new AuthorizationException('Retomar a atualização das obrigações exige a permissão pu.operations.recover.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Informe o motivo da retomada.');
        }

        $rearmed = DB::transaction(function () use ($emissionId, $actor): array {
            $stuck = PuObligationRefreshRequest::query()
                ->where('emission_id', $emissionId)
                ->whereIn('status', [PuObligationRefreshStatus::Exhausted->value, PuObligationRefreshStatus::Blocked->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($stuck->isEmpty()) {
                return [[], [$this->request($emissionId, 'manual_retry', (int) $actor->getKey())->id]];
            }

            $previous = [];

            foreach ($stuck as $request) {
                $previous[] = ['id' => $request->id, 'status' => $request->status->value, 'attempts' => $request->attempts];
                $request->forceFill([
                    'status' => PuObligationRefreshStatus::Pending,
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                ])->save();
            }

            return [$previous, $stuck->pluck('id')->map(fn ($id): int => (int) $id)->all()];
        });

        [$previous, $requestIds] = $rearmed;
        $outcome = $this->process($emissionId, self::VIA_MANUAL, (int) $actor->getKey());
        $this->auditLog->logObligationRefreshRetried($emissionId, $actor, $reason, $requestIds, $previous, $outcome);

        return $outcome;
    }

    /**
     * Reserva, numa transação curta, os pedidos elegíveis da emissão.
     *
     * @return array{token: string, claimed: list<int>, covered: list<int>, trigger: string, attempts: int, started_at: CarbonImmutable}|null
     */
    private function claim(int $emissionId, string $via, CarbonImmutable $now): ?array
    {
        return DB::transaction(function () use ($emissionId, $via, $now): ?array {
            $open = PuObligationRefreshRequest::query()
                ->where('emission_id', $emissionId)
                ->open()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $eligible = $open->filter(fn (PuObligationRefreshRequest $request): bool => $this->isClaimable($request, $via, $now))->values();

            if ($eligible->isEmpty()) {
                return null;
            }

            $token = (string) Str::uuid();
            $maxAttempts = $this->maxAttempts();
            $claimed = [];

            foreach ($eligible as $request) {
                $interrupted = $request->status === PuObligationRefreshStatus::Running;

                if ($interrupted && $request->attempts >= $maxAttempts) {
                    $request->forceFill([
                        'status' => PuObligationRefreshStatus::Exhausted,
                        'claim_token' => null,
                        'claim_expires_at' => null,
                        'last_error_category' => PuOperationalFailureCategory::Interrupted,
                        'last_error_message' => 'A última tentativa começou e não terminou (processo interrompido).',
                    ])->save();

                    continue;
                }

                $request->forceFill([
                    'status' => PuObligationRefreshStatus::Running,
                    'claim_token' => $token,
                    'claimed_via' => $via,
                    'claimed_at' => $now,
                    'claim_expires_at' => $now->addSeconds($this->leaseSeconds()),
                    'attempts' => $request->attempts + 1,
                    'last_attempt_at' => $now,
                    ...($interrupted ? [
                        'last_error_category' => PuOperationalFailureCategory::Interrupted,
                        'last_error_message' => 'A tentativa anterior começou e não terminou (processo interrompido).',
                    ] : []),
                ])->save();
                $claimed[] = $request;
            }

            if ($claimed === []) {
                return null;
            }

            $claimedIds = array_map(fn (PuObligationRefreshRequest $request): int => (int) $request->id, $claimed);
            // Pedidos abertos já visíveis, que ninguém está executando agora: os fatos
            // deles comitaram antes desta reserva, então a recomposição os enxerga.
            $covered = $open
                ->reject(fn (PuObligationRefreshRequest $request): bool => in_array((int) $request->id, $claimedIds, true))
                ->reject(fn (PuObligationRefreshRequest $request): bool => $request->status === PuObligationRefreshStatus::Running
                    && $request->claim_expires_at !== null
                    && $request->claim_expires_at->greaterThanOrEqualTo($now))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
            $latest = $claimed[array_key_last($claimed)];
            $trigger = $via === self::VIA_AFTER_COMMIT ? (string) $latest->trigger : Str::limit($via.':'.$latest->trigger, 60, '');

            return [
                'token' => $token,
                'claimed' => $claimedIds,
                'covered' => $covered,
                'trigger' => $trigger,
                'attempts' => max(array_map(fn (PuObligationRefreshRequest $request): int => (int) $request->attempts, $claimed)),
                'started_at' => $now,
            ];
        });
    }

    private function isClaimable(PuObligationRefreshRequest $request, string $via, CarbonImmutable $now): bool
    {
        return match ($request->status) {
            // Logo depois do commit (e na execução manual) o pedido novo roda já;
            // a varredura respeita a carência, para não disputar com essa tentativa.
            PuObligationRefreshStatus::Pending => $via !== self::VIA_SCANNER
                || $request->next_attempt_at === null
                || $request->next_attempt_at->lessThanOrEqualTo($now),
            PuObligationRefreshStatus::RetryScheduled => $via === self::VIA_SCANNER
                && ($request->next_attempt_at === null || $request->next_attempt_at->lessThanOrEqualTo($now)),
            // Concessão vencida: a execução que o reservou morreu no meio.
            PuObligationRefreshStatus::Running => $via === self::VIA_SCANNER
                && $request->claim_expires_at !== null
                && $request->claim_expires_at->lessThan($now),
            default => false,
        };
    }

    /**
     * @param  array{token: string, claimed: list<int>, covered: list<int>, trigger: string, attempts: int, started_at: CarbonImmutable}  $claim
     */
    private function recordSuccess(int $emissionId, string $via, array $claim, PuObligationRefreshResult $result, bool $emissionExists): PuObligationRefreshOutcome
    {
        $payload = [
            'calculation_version' => $result->calculationVersion,
            'counts' => $result->counts,
            'emission_missing' => ! $emissionExists,
        ];
        $covered = [];

        DB::transaction(function () use ($claim, $payload, &$covered): void {
            $now = now();

            PuObligationRefreshRequest::query()
                ->whereIn('id', $claim['claimed'])
                ->where('claim_token', $claim['token'])
                ->update([
                    'status' => PuObligationRefreshStatus::Succeeded->value,
                    'completed_at' => $now,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'next_attempt_at' => null,
                    'result' => json_encode($payload),
                    'updated_at' => $now,
                ]);

            if ($claim['covered'] === []) {
                return;
            }

            // Só o que continua aberto e sem dono: um pedido que outro executor
            // reservou nesse meio tempo é dele.
            $covered = PuObligationRefreshRequest::query()
                ->whereIn('id', $claim['covered'])
                ->whereIn('status', [
                    PuObligationRefreshStatus::Pending->value,
                    PuObligationRefreshStatus::RetryScheduled->value,
                    PuObligationRefreshStatus::Exhausted->value,
                    PuObligationRefreshStatus::Blocked->value,
                ])
                ->whereNull('claim_token')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($covered !== []) {
                PuObligationRefreshRequest::query()->whereIn('id', $covered)->update([
                    'status' => PuObligationRefreshStatus::Superseded->value,
                    'completed_at' => $now,
                    'next_attempt_at' => null,
                    'satisfied_by_request_id' => $claim['claimed'][0],
                    'updated_at' => $now,
                ]);
            }
        });

        $outcome = new PuObligationRefreshOutcome(
            emissionId: $emissionId,
            via: $via,
            status: PuObligationRefreshOutcome::SUCCEEDED,
            claimedRequestIds: $claim['claimed'],
            coveredRequestIds: $covered,
            attempts: $claim['attempts'],
            result: $result,
        );

        // Recuperação de verdade (não a primeira tentativa logo depois do commit):
        // fica na trilha de diagnóstico.
        if ($via !== self::VIA_AFTER_COMMIT || $claim['attempts'] > 1 || $covered !== []) {
            $this->auditLog->logObligationRefreshRecovered($emissionId, $outcome);
        }

        return $outcome;
    }

    /**
     * @param  array{token: string, claimed: list<int>, covered: list<int>, trigger: string, attempts: int, started_at: CarbonImmutable}  $claim
     */
    private function recordFailure(int $emissionId, string $via, array $claim, Throwable $exception): PuObligationRefreshOutcome
    {
        $category = $this->failures->classify($exception);
        $message = $this->failures->sanitize($exception->getMessage());
        $attempts = $claim['attempts'];
        [$status, $outcomeStatus] = match (true) {
            ! $category->isRetryable() => [PuObligationRefreshStatus::Blocked, PuObligationRefreshOutcome::BLOCKED],
            $attempts >= $this->maxAttempts() => [PuObligationRefreshStatus::Exhausted, PuObligationRefreshOutcome::EXHAUSTED],
            default => [PuObligationRefreshStatus::RetryScheduled, PuObligationRefreshOutcome::RETRY_SCHEDULED],
        };
        $nextAttemptAt = $status === PuObligationRefreshStatus::RetryScheduled
            ? now()->addSeconds($this->backoffSeconds($attempts))
            : null;

        try {
            DB::transaction(function () use ($claim, $status, $category, $exception, $message, $nextAttemptAt): void {
                PuObligationRefreshRequest::query()
                    ->whereIn('id', $claim['claimed'])
                    ->where('claim_token', $claim['token'])
                    ->update([
                        'status' => $status->value,
                        'claim_token' => null,
                        'claim_expires_at' => null,
                        'next_attempt_at' => $nextAttemptAt,
                        'last_error_category' => $category->value,
                        'last_error_class' => Str::limit($exception::class, 190, ''),
                        'last_error_message' => $message,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $recording) {
            // Sem conseguir registrar, o pedido continua "em execução" e a concessão
            // vence: a varredura o retoma como interrompido. Nada se perde.
            report($recording);
        }

        $outcome = new PuObligationRefreshOutcome(
            emissionId: $emissionId,
            via: $via,
            status: $outcomeStatus,
            claimedRequestIds: $claim['claimed'],
            attempts: $attempts,
            failureCategory: $category,
            message: $message,
        );
        $this->auditLog->logObligationRefreshFailed($emissionId, $outcome);

        return $outcome;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('pu_calculator.obligation_refresh.max_attempts', 5));
    }

    private function leaseSeconds(): int
    {
        return max(60, (int) config('pu_calculator.obligation_refresh.lease_seconds', 900));
    }

    private function scannerGraceSeconds(): int
    {
        return max(0, (int) config('pu_calculator.obligation_refresh.scanner_grace_seconds', 60));
    }

    /**
     * Espera antes da tentativa seguinte à de número `$attempts` (1 = a primeira
     * falhou). Depois do último degrau da lista, repete o último.
     */
    private function backoffSeconds(int $attempts): int
    {
        /** @var list<int> $steps */
        $steps = array_values(array_filter(
            array_map('intval', (array) config('pu_calculator.obligation_refresh.backoff_seconds', [60, 300, 900, 3600])),
            fn (int $seconds): bool => $seconds > 0,
        ));

        if ($steps === []) {
            return 60;
        }

        return $steps[min(max(0, $attempts - 1), count($steps) - 1)];
    }

    /**
     * Pedidos abertos da emissão, do mais antigo ao mais novo (para o retrato).
     *
     * @return Collection<int, PuObligationRefreshRequest>
     */
    public function openRequests(?int $emissionId = null): Collection
    {
        return PuObligationRefreshRequest::query()
            ->when($emissionId !== null, fn ($query) => $query->where('emission_id', $emissionId))
            ->open()
            ->orderBy('id')
            ->get();
    }
}
