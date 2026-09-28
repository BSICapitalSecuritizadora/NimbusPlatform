<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Support\BusinessTime;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use App\Support\SalesBoards\SalesBoardAutomationLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * O que sobra de uma execução que morreu no meio.
 *
 * Nenhum processo grava o próprio fim quando é morto -- falta de memória não é
 * `Throwable`, e deploy e reinício do contêiner não passam por `catch` nenhum.
 * Então quem arruma é a execução seguinte, logo no começo: a linha que ficou
 * "executando" muito além do prazo vira "interrompida", com aviso, e a tentativa
 * que ela tinha reservado vira falha registrada. Sem isto a execução fantasma
 * ficava "executando" para sempre e o alvo pesado nunca contava tentativa.
 */
class SalesBoardAutomationRecoveryService
{
    public function __construct(
        private readonly SalesBoardAutomationTargetProcessor $processor,
        private readonly SalesBoardAutomationRecipientResolver $recipients,
        private readonly SalesBoardAutomationAlertDispatcher $alerts,
    ) {}

    /**
     * Marca como interrompidas as execuções que ficaram "executando" além do
     * prazo, e avisa.
     *
     * O prazo nunca é menor que o lock de sobreposição do scheduler: uma
     * execução ainda protegida por ele pode estar viva. A troca de estado é
     * condicional ("ainda executando"), então duas instâncias que cheguem juntas
     * encerram a mesma execução uma vez só.
     *
     * @return int quantas execuções foram dadas como interrompidas
     */
    public function reapInterruptedRuns(SalesBoardAutomationRun $current, CarbonImmutable $now): int
    {
        $limit = $now->subMinutes(SalesBoardAutomationConfig::staleRunMinutes());

        $stale = SalesBoardAutomationRun::query()
            ->where('status', SalesBoardAutomationRunStatus::Running)
            ->where('started_at', '<', $limit)
            ->whereKeyNot($current->getKey())
            ->orderBy('id')
            ->get();

        $reaped = 0;

        foreach ($stale as $run) {
            $updated = SalesBoardAutomationRun::query()
                ->whereKey($run->getKey())
                ->where('status', SalesBoardAutomationRunStatus::Running)
                ->update([
                    'status' => SalesBoardAutomationRunStatus::Interrupted->value,
                    'finished_at' => $now,
                    'failure_message' => sprintf(
                        'A execução não terminou: o processo foi encerrado no meio (falta de memória, deploy ou reinício). '
                            .'Dada como interrompida pela execução #%d, %d minutos depois do início.',
                        (int) $current->getKey(),
                        (int) $run->started_at->diffInMinutes($now),
                    ),
                ]);

            if ($updated !== 1) {
                continue;
            }

            $reaped++;

            Log::error('Sales board automation run was interrupted', [
                'event' => 'sales_board_automation_run_interrupted',
                'run_id' => (int) $run->getKey(),
                'started_at' => $run->started_at->toIso8601String(),
                'detected_by_run_id' => (int) $current->getKey(),
            ]);

            $this->alerts->dispatch(
                SalesBoardAutomationAlertType::RunInterrupted,
                $this->recipients->forRunInterrupted($run),
                ['sales_board_automation_run_id' => (int) $run->getKey()],
                'execucao-'.$run->getKey(),
                sprintf('Execução #%d da automação, iniciada em %s', (int) $run->getKey(), BusinessTime::at($run->started_at)->format('d/m/Y H:i')),
                '',
                'A execução mensal do Quadro de Vendas foi interrompida antes de terminar. As competências que ficaram pela metade '
                    .'serão tentadas de novo com espera crescente; se o problema se repetir, a causa provável é falta de memória no servidor.',
                SalesBoardAutomationLinks::automationScreen(),
            );
        }

        return $reaped;
    }

    /**
     * Registra como falha as tentativas reservadas por execuções que já não
     * estão rodando.
     *
     * @return int quantas tentativas interrompidas foram registradas
     */
    public function recordInterruptedAttempts(): int
    {
        $recorded = 0;

        SalesBoardAutomationTarget::query()
            ->whereNotNull('in_flight_run_id')
            ->whereIn('in_flight_run_id', SalesBoardAutomationRun::query()
                ->select('id')
                ->where('status', '!=', SalesBoardAutomationRunStatus::Running->value))
            ->orderBy('id')
            ->get(['id'])
            ->each(function (SalesBoardAutomationTarget $target) use (&$recorded): void {
                if ($this->processor->recordInterruption($target) !== null) {
                    $recorded++;
                }
            });

        return $recorded;
    }
}
