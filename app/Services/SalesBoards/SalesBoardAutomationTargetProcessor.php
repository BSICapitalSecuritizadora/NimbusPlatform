<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationClosureReason;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Models\Construction;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uma investida sobre um alvo, do lock ao registro do desfecho.
 *
 * Uma transação por alvo, e não uma pela carteira inteira: cem empreendimentos
 * numa transação só significaria segurar locks por minutos e perder tudo por
 * causa do último. Aqui o empreendimento A é decidido e commitado antes de B
 * começar, e a falha de B não desfaz A.
 *
 * A ordem de locks é alvo → ciclo, e ela não é arbitrária: a geração da Fase C
 * já trava o ciclo por dentro, então travar o alvo primeiro mantém a hierarquia
 * de fora para dentro. Inverter -- ler o ciclo antes de travar o alvo -- criaria
 * duas ordens de aquisição no mesmo sistema, que é como deadlock nasce.
 *
 * Nada aqui reimplementa apuração. Prontidão, derivação, fingerprint e criação
 * do ciclo continuam sendo do {@see SalesBoardGenerationService}; este serviço
 * decide *quando* chamar e o que fazer com a resposta.
 *
 * A tentativa tem duas transações, e a primeira é curta de propósito: ela
 * **reserva** a tentativa -- conta o número, marca a execução que está nela e já
 * grava a próxima tentativa como se esta fosse falhar -- e commita antes de a
 * geração começar. Um processo que morre no meio da geração (falta de memória,
 * deploy, reinício) é o único caso que nenhum `catch` alcança; com a reserva
 * commitada, a morte vira uma tentativa interrompida que a execução seguinte
 * registra, o backoff passa a valer e a escalação conta. Sem ela, a transação
 * desfeita apagava a própria tentativa: o alvo pesado voltava primeiro da fila a
 * cada hora, sem contar nada, e derrubava junto todos os que vinham depois.
 */
class SalesBoardAutomationTargetProcessor
{
    /**
     * O código da tentativa que um processo morto deixou pela metade.
     */
    public const INTERRUPTED_CODE = 'TentativaInterrompida';

    /**
     * O código da tentativa que encontrou a competência cancelada pela Gestão.
     */
    public const CANCELLED_COMPETENCE_CODE = 'competencia_cancelada';

    /**
     * O código da tentativa que encontrou uma competência posterior já
     * publicada.
     */
    public const LATER_PUBLISHED_CODE = 'competencia_posterior_publicada';

    public function __construct(
        private readonly SalesBoardGenerationService $generationService,
        private readonly SalesBoardAutomationRetryPolicy $retryPolicy,
        private readonly SalesBoardBuilderReviewOpeningService $builderReviewOpeningService,
    ) {}

    /**
     * Processa um alvo e devolve a tentativa registrada.
     *
     * Devolve `null` quando o alvo não devia mesmo tentar -- satisfeito ou
     * encerrado por outra instância entre a descoberta e agora, ainda em espera
     * de retry, ou com a tentativa reservada por outra execução. Não é erro, e
     * não vira tentativa: registrar "não tentei" a cada hora encheria a trilha
     * de linhas que não explicam nada.
     */
    public function process(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        CarbonImmutable $now,
    ): ?SalesBoardAutomationAttempt {
        $reservation = $this->reserve($run, $target, $now);

        if ($reservation === null) {
            return null;
        }

        [$attemptNumber, $startedAt] = $reservation;

        return DB::transaction(function () use ($run, $target, $now, $attemptNumber, $startedAt): ?SalesBoardAutomationAttempt {
            $locked = SalesBoardAutomationTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * Entre a reserva e agora o alvo pode ter sido encerrado (retorno ao
             * legado, suspensão de escopo). A reserva é desfeita e nada é
             * gerado: a competência não pertence mais à automação.
             */
            if ((int) $locked->in_flight_run_id !== (int) $run->getKey() || ! $locked->status->isOpen()) {
                if ((int) $locked->in_flight_run_id === (int) $run->getKey()) {
                    $locked->forceFill(['in_flight_run_id' => null])->save();
                }

                return null;
            }

            $existing = $this->existingCycleFor($locked);

            if ($existing instanceof SalesBoardCycle) {
                return $existing->status === SalesBoardCycleStatus::Cancelled
                    ? $this->closeForCancelledCompetence($run, $locked, $existing, $attemptNumber, $startedAt)
                    : $this->satisfy(
                        $run,
                        $locked,
                        $existing,
                        SalesBoardAutomationAttemptOutcome::Existing,
                        $attemptNumber,
                        $startedAt,
                    );
            }

            $construction = Construction::query()->find($locked->construction_id);

            if (! $construction instanceof Construction) {
                return $this->fail(
                    $run,
                    $locked,
                    $attemptNumber,
                    $startedAt,
                    $now,
                    'construction_missing',
                    'O empreendimento deste alvo não existe mais.',
                );
            }

            try {
                $result = $this->generationService->generateForConstruction(
                    $construction,
                    $locked->reference_month,
                );
            } catch (Throwable $exception) {
                return $this->fail(
                    $run,
                    $locked,
                    $attemptNumber,
                    $startedAt,
                    $now,
                    $this->errorCode($exception),
                    $this->sanitize($exception),
                    $exception,
                );
            }

            return match ($result->outcome) {
                SalesBoardGenerationOutcome::Generated => $this->satisfy(
                    $run,
                    $locked,
                    $result->cycle,
                    SalesBoardAutomationAttemptOutcome::Generated,
                    $attemptNumber,
                    $startedAt,
                ),
                SalesBoardGenerationOutcome::AlreadyExists => $result->cycle?->status === SalesBoardCycleStatus::Cancelled
                    ? $this->closeForCancelledCompetence($run, $locked, $result->cycle, $attemptNumber, $startedAt)
                    : $this->satisfy(
                        $run,
                        $locked,
                        $result->cycle,
                        SalesBoardAutomationAttemptOutcome::Existing,
                        $attemptNumber,
                        $startedAt,
                    ),
                SalesBoardGenerationOutcome::Blocked => $result->isBehindLaterPublication()
                    ? $this->closeForLaterPublication($run, $locked, $attemptNumber, $startedAt, (string) $result->blockedReason)
                    : $this->block(
                        $run,
                        $locked,
                        $attemptNumber,
                        $startedAt,
                        $now,
                        $result->readiness?->blockingIssueCounts() ?? [],
                        (string) $result->blockedReason,
                    ),
            };
        });
    }

    /**
     * Reserva a tentativa, numa transação própria e commitada antes da geração.
     *
     * Grava o que tem de valer se o processo morrer daqui em diante: o número da
     * tentativa, a execução que a reservou e a próxima tentativa já com o
     * backoff de uma falha técnica. Se a geração terminar, a segunda transação
     * sobrescreve tudo isso com o desfecho real.
     *
     * Reconferido sob lock, e não confiando na leitura da descoberta: entre uma
     * coisa e outra outra instância pode ter satisfeito ou reservado este mesmo
     * alvo, e tentar de novo produziria uma derivação inteira para descobrir o
     * que a linha já sabia.
     *
     * @return array{0: int, 1: CarbonImmutable}|null
     */
    private function reserve(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        CarbonImmutable $now,
    ): ?array {
        return DB::transaction(function () use ($run, $target, $now): ?array {
            $locked = SalesBoardAutomationTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SalesBoardAutomationTarget || ! $locked->isDueForAttempt($now)) {
                return null;
            }

            $attemptNumber = (int) $locked->attempt_count + 1;
            $startedAt = CarbonImmutable::now();

            $locked->forceFill([
                'attempt_count' => $attemptNumber,
                'in_flight_run_id' => $run->getKey(),
                'first_attempt_at' => $locked->first_attempt_at ?? $startedAt,
                'last_attempt_at' => $startedAt,
                'next_attempt_at' => $this->retryPolicy->afterFailure($now, (int) $locked->consecutive_failure_count + 1),
            ])->save();

            return [$attemptNumber, $startedAt];
        });
    }

    /**
     * Registra como falha a tentativa que um processo morto deixou reservada.
     *
     * Só vale para reservas de execuções que já não estão rodando -- terminadas,
     * falhas ou dadas como interrompidas. A reserva de uma execução viva é
     * trabalho em andamento, não órfão.
     *
     * A tentativa é acrescentada à trilha com a execução que a reservou, como
     * qualquer outra. O alvo ainda aberto passa a falho, com a falha contada:
     * a próxima tentativa já foi gravada com backoff na reserva, e é ela que
     * vale. Um alvo que nesse meio-tempo foi encerrado continua encerrado.
     */
    public function recordInterruption(SalesBoardAutomationTarget $target): ?SalesBoardAutomationAttempt
    {
        return DB::transaction(function () use ($target): ?SalesBoardAutomationAttempt {
            $locked = SalesBoardAutomationTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SalesBoardAutomationTarget || $locked->in_flight_run_id === null) {
                return null;
            }

            $run = SalesBoardAutomationRun::query()->find($locked->in_flight_run_id);

            if (! $run instanceof SalesBoardAutomationRun || $run->status === SalesBoardAutomationRunStatus::Running) {
                return null;
            }

            $message = 'A tentativa foi interrompida antes de terminar: o processo da automação foi encerrado no meio '
                .'(falta de memória, deploy ou reinício). O detalhe técnico, se houver, está no log da aplicação.';

            $state = ['in_flight_run_id' => null];

            if ($locked->status->isOpen()) {
                $state += [
                    'status' => SalesBoardAutomationTargetStatus::Failed,
                    'consecutive_failure_count' => (int) $locked->consecutive_failure_count + 1,
                    'last_outcome_at' => CarbonImmutable::now(),
                    'last_error_code' => self::INTERRUPTED_CODE,
                    'last_error_message' => $message,
                ];
            }

            $locked->forceFill($state)->save();

            return $this->recordAttempt(
                $run,
                $locked,
                (int) $locked->attempt_count,
                SalesBoardAutomationAttemptOutcome::Failed,
                $locked->last_attempt_at ?? CarbonImmutable::now(),
                null,
                self::INTERRUPTED_CODE,
                $message,
            );
        });
    }

    /**
     * Registra a falha de um alvo que estourou fora da geração.
     *
     * O orquestrador chama isto quando o próprio processamento -- lock, leitura,
     * gravação de estado -- lançou. É melhor esforço: se nem isto conseguir
     * gravar, a reserva fica e a execução seguinte a registra como
     * interrompida.
     */
    public function recordUnexpectedFailure(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        CarbonImmutable $now,
        Throwable $exception,
    ): ?SalesBoardAutomationAttempt {
        return DB::transaction(function () use ($run, $target, $now, $exception): ?SalesBoardAutomationAttempt {
            $locked = SalesBoardAutomationTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SalesBoardAutomationTarget
                || (int) $locked->in_flight_run_id !== (int) $run->getKey()
                || ! $locked->status->isOpen()) {
                return null;
            }

            return $this->fail(
                $run,
                $locked,
                (int) $locked->attempt_count,
                $locked->last_attempt_at ?? CarbonImmutable::now(),
                $now,
                $this->errorCode($exception),
                $this->sanitize($exception),
            );
        });
    }

    /**
     * O ciclo da competência, se já houver um.
     *
     * Qualquer ciclo conta, inclusive cancelado: a competência já tem
     * identidade, e a unique de `sales_board_cycles` recusaria outro. O ciclo em
     * andamento satisfaz o alvo; o cancelado o **encerra**
     * ({@see self::closeForCancelledCompetence()}) -- a Gestão encerrou a
     * competência, e a volta é a reabertura do mesmo ciclo por ela, não um
     * ciclo novo gerado pela automação.
     */
    private function existingCycleFor(SalesBoardAutomationTarget $target): ?SalesBoardCycle
    {
        $month = CarbonImmutable::parse($target->reference_month->toDateString())->startOfMonth();

        return SalesBoardCycle::query()
            ->where('construction_id', $target->construction_id)
            ->whereBetween('reference_month', [$month->toDateString(), InclusiveDateBound::upperBound($month)])
            ->first();
    }

    private function satisfy(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        ?SalesBoardCycle $cycle,
        SalesBoardAutomationAttemptOutcome $outcome,
        int $attemptNumber,
        CarbonImmutable $startedAt,
    ): SalesBoardAutomationAttempt {
        $target->forceFill([
            'status' => SalesBoardAutomationTargetStatus::Satisfied,
            'satisfied_via' => $outcome->satisfiedVia(),
            'sales_board_cycle_id' => $cycle?->getKey(),
            'attempt_count' => $attemptNumber,
            'consecutive_failure_count' => 0,
            'in_flight_run_id' => null,
            'first_attempt_at' => $target->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $startedAt,
            'next_attempt_at' => null,
            'last_outcome_at' => CarbonImmutable::now(),
            'last_blocker_codes' => null,
            'last_blocker_message' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $attempt = $this->recordAttempt($run, $target, $attemptNumber, $outcome, $startedAt, $cycle);

        $this->openBuilderReviewIfRequested($target, $cycle);

        return $attempt;
    }

    /**
     * Encerra o alvo cuja competência a Gestão cancelou.
     *
     * Acontece quando o cancelamento veio antes de o alvo existir -- um ciclo
     * congelado à mão e cancelado antes do dia 13. O cancelamento só alcança os
     * alvos que já existem; sem isto o alvo nascia pendente e terminava
     * "satisfeito (já existente)" apontando para o ciclo cancelado, como se a
     * competência tivesse sido atendida.
     *
     * O encerramento é o mesmo que o cancelamento produziria: motivo
     * `CompetenceCancelled`, a mensagem com o motivo do ciclo e quem cancelou.
     * A tentativa já reservada fica registrada como ignorada, apontando o ciclo,
     * e a validação da construtora nunca é aberta -- não há o que validar numa
     * competência encerrada. Só a reabertura da competência pela Gestão devolve
     * o alvo.
     */
    private function closeForCancelledCompetence(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        SalesBoardCycle $cycle,
        int $attemptNumber,
        CarbonImmutable $startedAt,
    ): SalesBoardAutomationAttempt {
        $reason = trim((string) $cycle->cancellation_reason);

        $target->forceFill([
            'status' => SalesBoardAutomationTargetStatus::Closed,
            'closure_reason' => SalesBoardAutomationClosureReason::CompetenceCancelled,
            'closure_message' => mb_substr(
                $reason === '' ? 'Competência cancelada pela Gestão.' : 'Competência cancelada pela Gestão: '.$reason,
                0,
                2000,
            ),
            'closed_at' => CarbonImmutable::now(),
            'closed_by_user_id' => $cycle->cancelled_by_user_id,
            'sales_board_cycle_id' => $cycle->getKey(),
            'attempt_count' => $attemptNumber,
            'consecutive_failure_count' => 0,
            'in_flight_run_id' => null,
            'first_attempt_at' => $target->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $startedAt,
            'next_attempt_at' => null,
            'last_outcome_at' => CarbonImmutable::now(),
            'last_blocker_codes' => null,
            'last_blocker_message' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $this->recordAttempt(
            $run,
            $target,
            $attemptNumber,
            SalesBoardAutomationAttemptOutcome::Skipped,
            $startedAt,
            $cycle,
            self::CANCELLED_COMPETENCE_CODE,
            'A competência foi cancelada pela Gestão; a automação não gera outro ciclo para o mesmo mês.',
        );
    }

    /**
     * Encerra o alvo cuja competência ficou para trás de uma competência
     * posterior do empreendimento já publicada.
     *
     * A geração recusa a competência para sempre -- a publicação posterior
     * reflete os fatos dela, e nunca é desfeita --, então tentar de novo a cada
     * dia só produziria alertas que ninguém resolve. O motivo é próprio e a
     * descoberta não o desfaz. A tentativa fica registrada como ignorada, com a
     * explicação da geração, e nenhum ciclo é criado.
     */
    private function closeForLaterPublication(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        int $attemptNumber,
        CarbonImmutable $startedAt,
        string $message,
    ): SalesBoardAutomationAttempt {
        $target->forceFill([
            'status' => SalesBoardAutomationTargetStatus::Closed,
            'closure_reason' => SalesBoardAutomationClosureReason::LaterCompetencePublished,
            'closure_message' => mb_substr($message, 0, 2000),
            'closed_at' => CarbonImmutable::now(),
            'closed_by_user_id' => null,
            'attempt_count' => $attemptNumber,
            'consecutive_failure_count' => 0,
            'in_flight_run_id' => null,
            'first_attempt_at' => $target->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $startedAt,
            'next_attempt_at' => null,
            'last_outcome_at' => CarbonImmutable::now(),
            'last_blocker_codes' => null,
            'last_blocker_message' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $this->recordAttempt(
            $run,
            $target,
            $attemptNumber,
            SalesBoardAutomationAttemptOutcome::Skipped,
            $startedAt,
            null,
            self::LATER_PUBLISHED_CODE,
            $message,
        );
    }

    /**
     * Abre a validação da construtora, quando o alvo pediu isso explicitamente.
     *
     * Fora da transação do alvo em espírito e em consequência: uma falha aqui
     * **não** desfaz o ciclo. O ciclo é o produto da automação e já está
     * garantido; a passagem de bastão é um passo seguinte, e derrubar uma
     * apuração inteira porque a abertura da revisão falhou trocaria um problema
     * pequeno por um grande.
     *
     * O serviço da Fase D aceita ator nulo, então não é preciso inventar um
     * usuário "Sistema" para isto -- a procedência está na trilha da automação.
     */
    private function openBuilderReviewIfRequested(
        SalesBoardAutomationTarget $target,
        ?SalesBoardCycle $cycle,
    ): void {
        if (! $target->auto_open_builder_review || ! $cycle instanceof SalesBoardCycle) {
            return;
        }

        DB::afterCommit(function () use ($target, $cycle): void {
            try {
                $this->builderReviewOpeningService->open($cycle->fresh());
            } catch (Throwable $exception) {
                Log::warning('Sales board automation could not hand the cycle to the builder', [
                    'event' => 'sales_board_automation_handoff_failed',
                    'target_id' => (int) $target->getKey(),
                    'cycle_id' => (int) $cycle->getKey(),
                    'reason_code' => $this->errorCode($exception),
                ]);
            }
        });
    }

    /**
     * @param  array<string, int>  $blockerCounts
     */
    private function block(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        int $attemptNumber,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
        array $blockerCounts,
        string $message,
    ): SalesBoardAutomationAttempt {
        $codes = array_keys($blockerCounts);

        $target->forceFill([
            'status' => SalesBoardAutomationTargetStatus::Blocked,
            'attempt_count' => $attemptNumber,
            /**
             * Bloqueio zera a sequência de falhas técnicas: a derivação rodou
             * até o fim, e a próxima falha técnica é a primeira de uma nova
             * sequência.
             */
            'consecutive_failure_count' => 0,
            'in_flight_run_id' => null,
            'first_attempt_at' => $target->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $startedAt,
            'next_attempt_at' => $this->retryPolicy->afterBlocked($now),
            'last_outcome_at' => CarbonImmutable::now(),
            'last_blocker_codes' => $codes,
            'last_blocker_message' => $message,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $this->recordAttempt(
            $run,
            $target,
            $attemptNumber,
            SalesBoardAutomationAttemptOutcome::Blocked,
            $startedAt,
            null,
            /**
             * O código estruturado que a prontidão já produz, não uma frase
             * reparseada: é ele que agrupa a métrica e que a tela traduz.
             */
            $codes === [] ? null : implode(',', $codes),
            $message,
        );
    }

    private function fail(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        int $attemptNumber,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
        string $code,
        string $message,
        ?Throwable $exception = null,
    ): SalesBoardAutomationAttempt {
        $consecutiveFailures = (int) $target->consecutive_failure_count + 1;

        $target->forceFill([
            'status' => SalesBoardAutomationTargetStatus::Failed,
            'attempt_count' => $attemptNumber,
            'consecutive_failure_count' => $consecutiveFailures,
            'in_flight_run_id' => null,
            'first_attempt_at' => $target->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $startedAt,
            'next_attempt_at' => $this->retryPolicy->afterFailure($now, $consecutiveFailures),
            'last_outcome_at' => CarbonImmutable::now(),
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();

        if ($exception !== null) {
            /**
             * O rastreamento técnico vai para o log da aplicação, que tem
             * retenção e controle de acesso próprios -- não para uma tabela de
             * negócio que ninguém trata como sensível.
             */
            report($exception);
        }

        return $this->recordAttempt(
            $run,
            $target,
            $attemptNumber,
            SalesBoardAutomationAttemptOutcome::Failed,
            $startedAt,
            null,
            $code,
            $message,
        );
    }

    private function recordAttempt(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        int $attemptNumber,
        SalesBoardAutomationAttemptOutcome $outcome,
        CarbonImmutable $startedAt,
        ?SalesBoardCycle $cycle = null,
        ?string $reasonCode = null,
        ?string $reasonMessage = null,
    ): SalesBoardAutomationAttempt {
        return SalesBoardAutomationAttempt::query()->create([
            'sales_board_automation_run_id' => $run->getKey(),
            'sales_board_automation_target_id' => $target->getKey(),
            'attempt_number' => $attemptNumber,
            'outcome' => $outcome,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
            'sales_board_cycle_id' => $cycle?->getKey(),
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
        ]);
    }

    /**
     * A classe da exceção, sem namespace: é o que agrupa a métrica sem virar
     * caminho de arquivo do servidor numa coluna de negócio.
     */
    private function errorCode(Throwable $exception): string
    {
        return mb_substr(class_basename($exception), 0, 120);
    }

    /**
     * A mensagem que pode ser persistida e mostrada.
     *
     * Mensagem de exceção carrega SQL, credencial de conexão e caminho de
     * arquivo com frequência demais para ser copiada inteira. O que fica é a
     * classe; o detalhe fica no log.
     */
    private function sanitize(Throwable $exception): string
    {
        return sprintf(
            'Falha técnica ao gerar a competência (%s). O detalhe técnico está no log da aplicação.',
            $this->errorCode($exception),
        );
    }
}
