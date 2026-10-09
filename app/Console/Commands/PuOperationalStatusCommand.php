<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuEmissionOperationalHealth;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use Illuminate\Console\Command;

/**
 * Retrato operacional do PU, só leitura (Fase 6): por emissão, curva oficial,
 * atualidade, fronteira realizada e esperada, motivo de bloqueio, obrigações,
 * liquidação, conciliação e pedidos pendentes; por indexador, divulgação e
 * sincronização. `--json` entrega a estrutura inteira para automação.
 */
class PuOperationalStatusCommand extends Command
{
    protected $signature = 'pu:operations:status
        {--emission=* : Restringe às emissões informadas (ID)}
        {--json : Imprime o retrato estruturado em JSON}';

    protected $description = 'Mostra o retrato operacional do PU (somente leitura): curva oficial, índice, obrigações, liquidação, conciliação e trabalho pendente.';

    public function handle(PuOperationalHealthService $health): int
    {
        $filter = array_values(array_filter(array_map('intval', (array) $this->option('emission'))));
        $snapshot = $health->snapshot(emissionIds: $filter === [] ? null : $filter);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Emissão', 'Elegibilidade', 'Indexador', 'Oficial', 'Atualidade', 'Realizada até', 'Esperada até', 'Bloqueio'],
            array_map(fn (PuEmissionOperationalHealth $emission): array => [
                $emission->emissionId,
                $emission->eligibility->label(),
                $emission->indexer?->value ?? '—',
                $emission->officialStatus?->calculationVersion ?? '—',
                $emission->freshness()?->label() ?? '—',
                $emission->officialStatus?->realizedThrough?->format('d/m/Y') ?? '—',
                $emission->officialStatus?->expectedRealizedThrough?->format('d/m/Y') ?? '—',
                $emission->blockingReason() ?? '—',
            ], $snapshot->emissions),
        );

        foreach ($snapshot->checks as $check => $result) {
            if (($result['status'] ?? 'failed') !== 'ok') {
                $this->warn(sprintf('Verificação %s não concluiu: %s', $check, (string) ($result['error'] ?? 'falha por emissão')));
            }
        }

        return self::SUCCESS;
    }
}
