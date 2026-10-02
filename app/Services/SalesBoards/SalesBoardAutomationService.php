<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationRunTrigger;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use App\Support\SalesBoards\SalesBoardAutomationPerimeter;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O orquestrador da automação mensal.
 *
 * Faz uma coisa: garante que exista um ciclo para cada competência devida de
 * cada empreendimento habilitado. E para aí -- não valida, não decide, não
 * aprova, não publica. A fronteira é deliberada e é o motivo de esta fase não
 * ter criado nenhuma regra financeira nova: ela executa automaticamente a
 * inteligência que as fases anteriores já homologaram.
 *
 * Uma falha de um empreendimento não derruba os outros. Cada alvo tem as suas
 * transações e o seu próprio `try`, e a execução termina relatando o que
 * conseguiu e o que não -- que é a diferença entre uma automação operável e uma
 * que precisa ser babysitada.
 *
 * Quem entra no perímetro é decidido pelo provider de elegibilidade, e é lá que
 * mora a regra de parada da Emissão liquidada: ela sai do perímetro, e a
 * execução encerra os alvos abertos dela, para de lembrar e avisa a Gestão uma
 * vez -- sem nenhuma lógica de datas a mais aqui.
 *
 * A duração de cada execução é medida com `hrtime()` e gravada em
 * milissegundos: os timestamps da execução não guardam fração de segundo, e a
 * diferença entre eles transformava 0,75 s em zero e 1,96 s em um segundo.
 */
class SalesBoardAutomationService
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly SalesBoardAutomationDueDateService $dueDates,
        private readonly SalesBoardAutomationDiscoveryService $discovery,
        private readonly SalesBoardAutomationTargetProcessor $processor,
        private readonly SalesBoardAutomationReminderService $reminders,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
        private readonly SalesBoardAutomationRecoveryService $recovery,
        private readonly SalesBoardAutomationTargetClosureService $closures,
        private readonly SalesBoardAutomationSuspensionNotifier $suspensions,
    ) {}

    /**
     * Executa uma rodada.
     *
     * `$asOf` existe para reprodução temporal em teste e UAT; omitido, a data de
     * negócio é a de agora. Quem chama é responsável por não permitir uma
     * execução com escrita e data forçada em produção -- a recusa vive no
     * comando, que é onde a intenção do operador aparece.
     */
    public function run(
        SalesBoardAutomationRunTrigger $trigger = SalesBoardAutomationRunTrigger::Scheduled,
        ?CarbonImmutable $asOf = null,
        bool $dryRun = false,
    ): SalesBoardAutomationRun {
        $startedNs = hrtime(true);
        $businessDate = $asOf?->startOfDay() ?? $this->dueDates->businessDate();
        $now = CarbonImmutable::now();

        /**
         * Desligado, volta antes de qualquer coisa: sem execução registrada, sem
         * descoberta, sem alvo, sem lembrete. O que sai é a mesma prévia vazia e
         * não persistida -- um interruptor que ainda gravasse "só a execução"
         * seria uma automação rodando devagar, não uma automação desligada.
         */
        if (! $this->enabled()) {
            return $this->previewRun($trigger, $businessDate, $now, $startedNs);
        }

        $this->alerts->reset();

        if ($dryRun) {
            return $this->previewRun($trigger, $businessDate, $now, $startedNs);
        }

        $run = $this->startRun($trigger, $businessDate, $now);

        $counters = $this->emptyCounters();
        $stage = 'início';

        try {
            $this->execute($run, $businessDate, $now, $counters, $stage, $startedNs);
        } catch (Throwable $exception) {
            /**
             * Chega aqui o que estourou fora do laço dos alvos -- a recuperação
             * de execuções interrompidas, a descoberta, o encerramento de alvos
             * órfãos. Uma falha de alvo é capturada no laço e vira estado do
             * alvo; uma falha dos lembretes vira "concluída com falhas". A etapa
             * vai na mensagem, e o que já tinha sido contado é gravado: uma
             * execução que processou metade dos alvos não pode aparecer como se
             * não tivesse processado nenhum.
             */
            report($exception);

            $alerts = $this->alerts->counters();

            $run->forceFill([
                ...$this->counterColumns($counters, $alerts),
                'status' => SalesBoardAutomationRunStatus::Failed,
                'finished_at' => CarbonImmutable::now(),
                'duration_ms' => $this->elapsedMilliseconds($startedNs),
                'failure_message' => sprintf(
                    'Falha técnica na orquestração, na etapa de %s (%s). O detalhe técnico está no log da aplicação.',
                    $stage,
                    class_basename($exception),
                ),
            ])->save();

            $this->log($run);

            return $run->refresh();
        }

        $this->log($run);

        return $run->refresh();
    }

    /**
     * @param  array{discovered: int, attempted: int, generated: int, existing: int, blocked: int, failed: int, skipped: int}  $counters
     */
    private function execute(
        SalesBoardAutomationRun $run,
        CarbonImmutable $businessDate,
        CarbonImmutable $now,
        array &$counters,
        string &$stage,
        int $startedNs,
    ): void {
        /**
         * Primeiro, o que a execução anterior deixou pela metade: quem morreu
         * não grava o próprio fim, então é aqui que ele é gravado.
         */
        $stage = 'recuperação de execuções interrompidas';
        $this->recovery->reapInterruptedRuns($run, $now);
        $this->recovery->recordInterruptedAttempts();

        /**
         * O provider é consultado uma vez só: a mesma resposta decide a
         * descoberta, o que sai do perímetro e o recorte dos lembretes.
         */
        $stage = 'descoberta';
        $eligible = $this->discovery->eligible();
        $perimeter = SalesBoardAutomationPerimeter::fromEligibleTargets($eligible);

        $candidates = $this->discovery->discover($businessDate, $eligible);
        $targets = $this->discovery->materialize($candidates);

        /**
         * Só o que ainda pede alguma coisa: o histórico satisfeito não é
         * "descoberto" de novo a cada hora.
         */
        $counters['discovered'] = count($targets);
        $run->forceFill(['targets_discovered' => $counters['discovered']])->save();

        $stage = 'encerramento de alvos fora do perímetro';
        $this->closures->closeOutsidePerimeter($perimeter);
        $this->suspensions->notify($perimeter);

        $targetIds = array_map(fn (SalesBoardAutomationTarget $target): int => (int) $target->getKey(), $targets);

        $attemptable = $this->discovery->attemptable($targetIds, $now);
        $counters['skipped'] = $this->discovery->countWaiting($targetIds, $now);

        $stage = 'processamento dos alvos';

        foreach ($attemptable as $target) {
            try {
                $attempt = $this->processor->process($run, $target, $now);
            } catch (Throwable $exception) {
                $this->recoverFromTargetException($run, $target, $now, $exception, $counters);

                continue;
            }

            if ($attempt === null) {
                $counters['skipped']++;

                continue;
            }

            $counters['attempted']++;

            match ($attempt->outcome) {
                SalesBoardAutomationAttemptOutcome::Generated => $counters['generated']++,
                SalesBoardAutomationAttemptOutcome::Existing => $counters['existing']++,
                SalesBoardAutomationAttemptOutcome::Blocked => $counters['blocked']++,
                SalesBoardAutomationAttemptOutcome::Failed => $counters['failed']++,
                SalesBoardAutomationAttemptOutcome::Skipped => $counters['skipped']++,
            };
        }

        /**
         * Os lembretes rodam depois da geração e fora dela: um aviso que falha
         * não pode desfazer uma competência apurada.
         *
         * E avaliam o mundo num instante tomado **depois** do processamento, não
         * no `$now` do início. A tentativa grava `first_attempt_at` e o ciclo
         * gravado tem `updated_at` com o relógio de quando aconteceram -- depois
         * do início --, e os limiares comparam esses instantes com precisão de
         * segundo. Com o `$now` do início, bastava o relógio virar o segundo
         * durante a geração para os lembretes de limiar zero (bloqueio e
         * "pronta para a construtora") ficarem de fora, e o aviso do mesmo dia
         * só sair na execução seguinte. A janela de deduplicação passa a ser o
         * dia de negócio desse mesmo instante.
         *
         * E um lembrete que estoura não transforma em "falhou" uma execução que
         * gerou as competências: ela termina "concluída com falhas", com a causa.
         */
        $stage = 'lembretes';
        $reminderFailure = null;

        try {
            $this->reminders->run(CarbonImmutable::now(), $perimeter);
        } catch (Throwable $exception) {
            report($exception);

            $reminderFailure = sprintf(
                'Os lembretes falharam (%s); a geração das competências terminou normalmente. O detalhe técnico está no log da aplicação.',
                class_basename($exception),
            );
        }

        $stage = 'finalização';
        $alerts = $this->alerts->counters();

        $run->forceFill([
            ...$this->counterColumns($counters, $alerts),
            'status' => $reminderFailure === null
                ? SalesBoardAutomationRunStatus::fromCounters($counters['failed'], $counters['blocked'], $alerts['failed'])
                : SalesBoardAutomationRunStatus::CompletedWithFailures,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => $this->elapsedMilliseconds($startedNs),
            'failure_message' => $reminderFailure,
        ])->save();
    }

    /**
     * Milissegundos desde `$startedNs`, pelo relógio monotônico do `hrtime()`:
     * não anda para trás com ajuste de hora do servidor nem depende do relógio
     * congelado dos testes.
     */
    private function elapsedMilliseconds(int $startedNs): int
    {
        return intdiv(max(0, hrtime(true) - $startedNs), 1_000_000);
    }

    /**
     * Um alvo estourou fora da geração -- lock, leitura, gravação de estado.
     *
     * O lote segue. Quando a tentativa já estava reservada por esta execução, a
     * falha é registrada no alvo, com backoff, como qualquer falha técnica.
     * Quando nem a reserva aconteceu porque outra instância segura a linha
     * (espera de lock, deadlock), não é falha desta execução: a outra está
     * trabalhando no alvo, e ele conta como ignorado.
     *
     * @param  array{discovered: int, attempted: int, generated: int, existing: int, blocked: int, failed: int, skipped: int}  $counters
     */
    private function recoverFromTargetException(
        SalesBoardAutomationRun $run,
        SalesBoardAutomationTarget $target,
        CarbonImmutable $now,
        Throwable $exception,
        array &$counters,
    ): void {
        $recorded = null;

        try {
            $recorded = $this->processor->recordUnexpectedFailure($run, $target, $now, $exception);
        } catch (Throwable $secondary) {
            report($secondary);
        }

        if ($recorded !== null) {
            report($exception);

            $counters['attempted']++;
            $counters['failed']++;

            return;
        }

        if ($this->causedByConcurrencyError($exception)) {
            Log::warning('Sales board automation target skipped: another process holds it', [
                'event' => 'sales_board_automation_target_contended',
                'run_id' => (int) $run->getKey(),
                'target_id' => (int) $target->getKey(),
                'reason_code' => class_basename($exception),
            ]);

            $counters['skipped']++;

            return;
        }

        report($exception);

        $counters['failed']++;
    }

    /**
     * @return array{discovered: int, attempted: int, generated: int, existing: int, blocked: int, failed: int, skipped: int}
     */
    private function emptyCounters(): array
    {
        return [
            'discovered' => 0,
            'attempted' => 0,
            'generated' => 0,
            'existing' => 0,
            'blocked' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @param  array{discovered: int, attempted: int, generated: int, existing: int, blocked: int, failed: int, skipped: int}  $counters
     * @param  array{sent: int, deduped: int, without_recipient: int, failed: int}  $alerts
     * @return array<string, int>
     */
    private function counterColumns(array $counters, array $alerts): array
    {
        return [
            'targets_discovered' => $counters['discovered'],
            'targets_attempted' => $counters['attempted'],
            'generated_count' => $counters['generated'],
            'existing_count' => $counters['existing'],
            'blocked_count' => $counters['blocked'],
            'failed_count' => $counters['failed'],
            'skipped_count' => $counters['skipped'],
            'alerts_sent' => $alerts['sent'],
            'alerts_deduped' => $alerts['deduped'],
            'alerts_failed' => $alerts['failed'],
            'alerts_without_recipient' => $alerts['without_recipient'],
        ];
    }

    /**
     * A prévia: descobre, projeta e **não grava nada**.
     *
     * Nem execução, nem alvo, nem tentativa, nem ciclo. Uma prévia que gravasse
     * a própria execução já teria mudado o banco antes de o operador decidir --
     * e o `--dry-run` existe exatamente para quem ainda não decidiu.
     */
    private function previewRun(
        SalesBoardAutomationRunTrigger $trigger,
        CarbonImmutable $businessDate,
        CarbonImmutable $now,
        int $startedNs,
    ): SalesBoardAutomationRun {
        $candidates = $this->enabled()
            ? $this->discovery->unsettled($this->discovery->discover($businessDate))
            : [];

        $run = new SalesBoardAutomationRun([
            'trigger' => $trigger,
            'status' => SalesBoardAutomationRunStatus::Completed,
            'as_of_date' => $businessDate->toDateString(),
            'latest_due_reference_month' => $this->dueDates->latestDueReferenceMonth($businessDate)->toDateString(),
            'started_at' => $now,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => $this->elapsedMilliseconds($startedNs),
            'targets_discovered' => count($candidates),
            'instance_key' => $this->instanceKey(),
        ]);

        /**
         * Devolvido sem `save()` e com os candidatos anexados: o comando precisa
         * mostrar o que aconteceria, e um objeto não persistido diz isso melhor
         * do que um array solto.
         */
        $run->setRelation('previewCandidates', collect($candidates));

        return $run;
    }

    private function startRun(
        SalesBoardAutomationRunTrigger $trigger,
        CarbonImmutable $businessDate,
        CarbonImmutable $now,
    ): SalesBoardAutomationRun {
        return SalesBoardAutomationRun::query()->create([
            'trigger' => $trigger,
            'status' => SalesBoardAutomationRunStatus::Running,
            'as_of_date' => $businessDate->toDateString(),
            'latest_due_reference_month' => $this->dueDates->latestDueReferenceMonth($businessDate)->toDateString(),
            'started_at' => $now,
            'instance_key' => $this->instanceKey(),
        ]);
    }

    /**
     * Uma linha por execução, agregada.
     *
     * Agregada de propósito: registrar cada alvo saudável encheria o log e
     * esconderia o que importa. O detalhe por alvo já está no banco, com
     * consulta e tela próprias -- e o que precisa de ação sai como `warning`,
     * uma vez, com os números.
     */
    private function log(SalesBoardAutomationRun $run): void
    {
        $summary = $run->toSummaryArray();

        Log::info('Sales board automation run complete', $summary);

        if (! $run->status->isTechnicallyHealthy()) {
            Log::warning('Sales board automation run finished with failures', $summary);
        }
    }

    private function enabled(): bool
    {
        return SalesBoardAutomationConfig::enabled();
    }

    /**
     * Qual processo executou.
     *
     * Não participa da correção -- essa é do banco. Serve à investigação: quando
     * duas execuções competem, saber se vieram de processos diferentes separa
     * "duas instâncias" de "um laço duplicado".
     */
    private function instanceKey(): string
    {
        return mb_substr(gethostname().':'.getmypid(), 0, 64);
    }
}
