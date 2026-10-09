<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Models\PuOperationalIncident;
use Illuminate\Console\Command;

/**
 * Rodada do monitor operacional do PU (Fase 6): avalia curva oficial, índice,
 * obrigações, liquidação, conciliação e trabalho pendente; abre, atualiza e
 * resolve incidentes; avisa quem tem `pu.operations.monitor`.
 *
 * Só observa. Seguro para repetir e para rodar em paralelo. Sai com falha quando
 * a própria rodada não concluiu (ou concluiu em parte) -- o agendador vê o
 * monitor quebrado; incidente aberto não é falha do comando.
 */
class RunPuOperationalMonitorCommand extends Command
{
    protected $signature = 'pu:operations:monitor
        {--no-notify : Avalia e registra incidentes sem avisar no painel}';

    protected $description = 'Avalia a saúde operacional do PU e mantém os incidentes (deduplicados) sem alterar nenhum fato financeiro.';

    public function handle(PuOperationalMonitor $monitor): int
    {
        $run = $monitor->run('command', ! (bool) $this->option('no-notify'));

        $this->table(['Execução', 'Situação', 'Condições', 'Abertos', 'Atualizados', 'Resolvidos', 'Avisados'], [[
            $run->id,
            $run->status->label(),
            $run->conditions_count,
            $run->incidents_opened,
            $run->incidents_updated,
            $run->incidents_resolved,
            $run->notifications_sent,
        ]]);

        foreach ((array) $run->checks as $check => $result) {
            if (($result['status'] ?? 'failed') !== 'ok') {
                $this->warn(sprintf('Verificação %s: %s %s', $check, $result['status'] ?? 'failed', (string) ($result['error'] ?? '')));
            }
        }

        if ($run->status === PuMonitorRunStatus::Failed) {
            $this->error('O monitor não concluiu: '.(string) $run->error);

            return self::FAILURE;
        }

        $this->line(sprintf('Incidentes abertos: %d.', PuOperationalIncident::query()->open()->count()));

        return $run->status === PuMonitorRunStatus::Succeeded ? self::SUCCESS : self::FAILURE;
    }
}
