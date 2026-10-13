<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\PuOperationalPreflightService;
use Illuminate\Console\Command;

/**
 * Preflight operacional do PU (Fase 7): o que precisa estar verificado antes de
 * ativar a operação do PU de uma emissão. Só leitura -- não ativa, não gera, não
 * homologa e não grava nada. Sai com falha quando algum item bloqueia.
 */
class PuOperationalPreflightCommand extends Command
{
    protected $signature = 'pu:operations:preflight
        {--emission=* : Avalia só as emissões informadas (ID)}
        {--json : Saída estruturada em JSON}';

    protected $description = 'Preflight read-only da operação do PU: interruptor global, indexadores, prontidão e ativação por emissão, agenda, fila, monitor, alertas, pendências, autoridades e retenção.';

    public function handle(PuOperationalPreflightService $preflight): int
    {
        $emissionIds = array_values(array_map('intval', array_filter((array) $this->option('emission'), 'is_numeric')));
        $report = $preflight->run($emissionIds !== [] ? $emissionIds : null);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['blocked'] ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Requisito', 'Resultado', 'Evidência', 'Ação restante'], array_map(fn (array $item): array => [
            $item['requirement'],
            mb_strtoupper($item['result']),
            $item['evidence'],
            $item['action'],
        ], $report['items']));

        if ($report['emissions'] !== []) {
            $this->table(['Emissão', 'Prontidão', 'Ativação', 'Bloqueios', 'Pontos a decidir'], array_map(fn (array $emission): array => [
                sprintf('#%d %s', $emission['emission_id'], $emission['emission_name']),
                $emission['status'],
                $emission['activation_status'],
                $emission['blockers'] === [] ? '—' : implode("\n", $emission['blockers']),
                $emission['review_items'] === [] ? '—' : implode("\n", $emission['review_items']),
            ], $report['emissions']));
        }

        $this->line($report['globally_enabled']
            ? 'Operações do PU LIGADAS neste ambiente: só emissões ativadas operam.'
            : 'Operações do PU DESLIGADAS neste ambiente: nada é gerado, estendido, homologado ou liquidado.');

        return $report['blocked'] ? self::FAILURE : self::SUCCESS;
    }
}
