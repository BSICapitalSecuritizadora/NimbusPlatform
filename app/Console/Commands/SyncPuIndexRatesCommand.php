<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Jobs\SyncIndexRatesFromBcbJob;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncPuIndexRatesCommand extends Command
{
    protected $signature = 'pu:index-rates:sync
        {--indexer= : cdi | ipca (vazio = ambos)}
        {--from= : data inicial YYYY-MM-DD (default: hoje - janela configurada)}
        {--to= : data final YYYY-MM-DD (default: hoje)}
        {--source=bcb : fonte da sincronização (apenas bcb suportado)}
        {--dry-run : simula sem persistir}
        {--force : força atualização (política overwrite)}
        {--queue : enfileira a sincronização (assíncrona)}';

    protected $description = 'Sincroniza índices publicados (CDI/IPCA) a partir da API do Banco Central (SGS).';

    public function handle(IndexRateSyncService $service): int
    {
        $source = (string) $this->option('source');

        if ($source !== 'bcb') {
            $this->error(sprintf('Fonte "%s" não suportada. Apenas "bcb" está disponível nesta fase.', $source));

            return self::FAILURE;
        }

        $indexers = $this->resolveIndexers();

        if ($indexers === []) {
            $this->error('Indexador inválido. Use --indexer=cdi ou --indexer=ipca.');

            return self::FAILURE;
        }

        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to')) : CarbonImmutable::now();
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'))
            : $to->subDays((int) config('pu_indexes.bcb.default_window_days', 45));

        $dryRun = (bool) $this->option('dry-run');
        $policy = (bool) $this->option('force') ? IndexRateSyncService::POLICY_OVERWRITE : null;

        $hadError = false;

        foreach ($indexers as $indexer) {
            if ((bool) $this->option('queue')) {
                SyncIndexRatesFromBcbJob::dispatch(
                    strtolower($indexer->value),
                    $from->toDateString(),
                    $to->toDateString(),
                    $dryRun,
                    null,
                    $policy,
                );

                $this->info(sprintf('Sincronização de %s enfileirada (%s a %s).', $indexer->value, $from->toDateString(), $to->toDateString()));

                continue;
            }

            try {
                $result = $service->sync($indexer, $from, $to, $dryRun, null, $policy);
            } catch (BcbSgsException $exception) {
                $this->error(sprintf('[%s] Falha no Banco Central: %s', $indexer->value, $exception->getMessage()));
                $hadError = true;

                continue;
            }

            $this->renderSummary($result->toArray());

            if ($result->hasErrors()) {
                foreach ($result->errors as $message) {
                    $this->warn(sprintf('[%s] %s', $indexer->value, $message));
                }
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<PuIndexer>
     */
    private function resolveIndexers(): array
    {
        $option = $this->option('indexer');

        if ($option === null || $option === '') {
            return [PuIndexer::Cdi, PuIndexer::Ipca];
        }

        return match (strtolower((string) $option)) {
            'cdi' => [PuIndexer::Cdi],
            'ipca' => [PuIndexer::Ipca],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->table(
            ['indexer', 'fonte', 'serie', 'periodo', 'consultados', 'criados', 'atualizados', 'ignorados', 'dry-run'],
            [[
                $summary['indexer'],
                $summary['source'],
                $summary['external_series_code'],
                sprintf('%s a %s', $summary['from'], $summary['to']),
                $summary['fetched'],
                $summary['created'],
                $summary['updated'],
                $summary['skipped'],
                $summary['dry_run'] ? 'sim' : 'nao',
            ]],
        );
    }
}
