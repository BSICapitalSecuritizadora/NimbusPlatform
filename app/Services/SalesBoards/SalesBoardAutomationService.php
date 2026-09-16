<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationRunTrigger;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Carbon\CarbonImmutable;
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
 * Uma falha de um empreendimento não derruba os outros. Cada alvo tem a sua
 * transação, e a execução termina relatando o que conseguiu e o que não -- que é
 * a diferença entre uma automação operável e uma que precisa ser babysitada.
 */
class SalesBoardAutomationService
{
    public function __construct(
        private readonly SalesBoardAutomationDueDateService $dueDates,
        private readonly SalesBoardAutomationDiscoveryService $discovery,
        private readonly SalesBoardAutomationTargetProcessor $processor,
        private readonly SalesBoardAutomationReminderService $reminders,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
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
        $businessDate = $asOf?->startOfDay() ?? $this->dueDates->businessDate();
        $now = CarbonImmutable::now();

        /**
         * Desligado, volta antes de qualquer coisa: sem execução registrada, sem
         * descoberta, sem alvo, sem lembrete. O que sai é a mesma prévia vazia e
         * não persistida -- um interruptor que ainda gravasse "só a execução"
         * seria uma automação rodando devagar, não uma automação desligada.
         */
        if (! $this->enabled()) {
            return $this->previewRun($trigger, $businessDate, $now);
        }

        $this->alerts->reset();

        if ($dryRun) {
            return $this->previewRun($trigger, $businessDate, $now);
        }

        $run = $this->startRun($trigger, $businessDate, $now);

        try {
            $this->execute($run, $businessDate, $now);
        } catch (Throwable $exception) {
            /**
             * Só chega aqui o que estourou **antes** de os alvos serem
             * processados -- a descoberta, tipicamente. Uma falha de alvo é
             * capturada pelo processador e vira estado do alvo, não morte da
             * execução.
             */
            report($exception);

            $run->forceFill([
                'status' => SalesBoardAutomationRunStatus::Failed,
                'finished_at' => CarbonImmutable::now(),
                'failure_message' => sprintf(
                    'Falha técnica na orquestração (%s). O detalhe técnico está no log da aplicação.',
                    class_basename($exception),
                ),
            ])->save();

            $this->log($run);

            return $run->refresh();
        }

        $this->log($run);

        return $run->refresh();
    }

    private function execute(
        SalesBoardAutomationRun $run,
        CarbonImmutable $businessDate,
        CarbonImmutable $now,
    ): void {
        $candidates = $this->enabled() ? $this->discovery->discover($businessDate) : [];

        $run->forceFill(['targets_discovered' => count($candidates)])->save();

        $targets = $this->discovery->materialize($candidates);
        $targetIds = array_map(fn (SalesBoardAutomationTarget $target): int => (int) $target->getKey(), $targets);

        $attemptable = $this->discovery->attemptable($targetIds, $now);
        $waiting = $this->discovery->countWaiting($targetIds, $now);

        $counters = [
            'attempted' => 0,
            'generated' => 0,
            'existing' => 0,
            'blocked' => 0,
            'failed' => 0,
            'skipped' => $waiting,
        ];

        foreach ($attemptable as $target) {
            $attempt = $this->processor->process($run, $target, $now);

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
         * não pode desfazer uma competência apurada, e uma competência apurada
         * agora mesmo não deve gerar lembrete de atraso no mesmo instante.
         */
        $this->reminders->run($now);

        $alerts = $this->alerts->counters();

        $run->forceFill([
            'status' => SalesBoardAutomationRunStatus::fromCounters($counters['failed'], $counters['blocked']),
            'finished_at' => CarbonImmutable::now(),
            'targets_attempted' => $counters['attempted'],
            'generated_count' => $counters['generated'],
            'existing_count' => $counters['existing'],
            'blocked_count' => $counters['blocked'],
            'failed_count' => $counters['failed'],
            'skipped_count' => $counters['skipped'],
            'alerts_sent' => $alerts['sent'],
            'alerts_deduped' => $alerts['deduped'],
        ])->save();
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
    ): SalesBoardAutomationRun {
        $candidates = $this->enabled() ? $this->discovery->discover($businessDate) : [];

        $run = new SalesBoardAutomationRun([
            'trigger' => $trigger,
            'status' => SalesBoardAutomationRunStatus::Completed,
            'as_of_date' => $businessDate->toDateString(),
            'latest_due_reference_month' => $this->dueDates->latestDueReferenceMonth($businessDate)->toDateString(),
            'started_at' => $now,
            'finished_at' => CarbonImmutable::now(),
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
