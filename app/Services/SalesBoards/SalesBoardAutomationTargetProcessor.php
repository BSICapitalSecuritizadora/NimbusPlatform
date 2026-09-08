<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationTargetStatus;
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
 */
class SalesBoardAutomationTargetProcessor
{
    public function __construct(
        private readonly SalesBoardGenerationService $generationService,
        private readonly SalesBoardAutomationRetryPolicy $retryPolicy,
        private readonly SalesBoardBuilderReviewOpeningService $builderReviewOpeningService,
    ) {}

    /**
     * Processa um alvo e devolve a tentativa registrada.
     *
     * Devolve `null` quando o alvo não devia mesmo tentar -- satisfeito por
     * outra instância entre a descoberta e agora, ou ainda em espera de retry.
     * Não é erro, e não vira tentativa: registrar "não tentei" a cada hora
     * encheria a trilha de linhas que não explicam nada.
     */
    public function process(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        CarbonImmutable $now,
    ): ?SalesBoardAutomationAttempt {
        return DB::transaction(function () use ($run, $target, $now): ?SalesBoardAutomationAttempt {
            $locked = SalesBoardAutomationTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * Reconferido sob lock, e não confiando na leitura da descoberta:
             * entre uma coisa e outra outra instância pode ter satisfeito este
             * mesmo alvo, e tentar de novo produziria uma derivação inteira para
             * descobrir o que a linha já sabia.
             */
            if (! $locked->isDueForAttempt($now)) {
                return null;
            }

            $attemptNumber = (int) $locked->attempt_count + 1;
            $startedAt = CarbonImmutable::now();

            $existing = $this->existingCycleFor($locked);

            if ($existing instanceof SalesBoardCycle) {
                return $this->satisfy(
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
                SalesBoardGenerationOutcome::AlreadyExists => $this->satisfy(
                    $run,
                    $locked,
                    $result->cycle,
                    SalesBoardAutomationAttemptOutcome::Existing,
                    $attemptNumber,
                    $startedAt,
                ),
                SalesBoardGenerationOutcome::Blocked => $this->block(
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
     * O ciclo da competência, se já houver um.
     *
     * Qualquer ciclo serve, inclusive cancelado: a competência já tem
     * identidade, e a unique de `sales_board_cycles` recusaria outro. Gerar
     * "outro ciclo porque aquele foi cancelado" é decisão de governança que
     * ninguém tomou, e a automação não é o lugar de tomá-la.
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
        $target->forceFill([
            'status' => SalesBoardAutomationTargetStatus::Failed,
            'attempt_count' => $attemptNumber,
            'first_attempt_at' => $target->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $startedAt,
            'next_attempt_at' => $this->retryPolicy->afterFailure($now, $attemptNumber),
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
