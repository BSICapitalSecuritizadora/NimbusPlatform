<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Cadastra um número-índice (NI) base de IPCA para servir de âncora à sincronização por variação.
 *
 * O SGS publica a VARIAÇÃO mensal do IPCA; o sistema precisa de um NI âncora anterior à primeira
 * competência para encadear o cálculo. Este comando cria essa base (default NI 100.000000), de forma
 * idempotente — nunca sobrescreve uma linha de IPCA já existente para a competência.
 */
class SeedIpcaAnchorCommand extends Command
{
    protected $signature = 'pu:index-rates:seed-ipca-anchor
        {--month= : competência da base YYYY-MM (obrigatório)}
        {--value=100 : número-índice base (default 100.000000)}
        {--source=manual_import : fonte registrada na linha}';

    protected $description = 'Cadastra um número-índice base (âncora) de IPCA para habilitar a sincronização por variação.';

    public function handle(): int
    {
        $month = (string) $this->option('month');

        if ($month === '') {
            $this->error('Informe a competência com --month=YYYY-MM.');

            return self::FAILURE;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m', $month)?->startOfMonth();
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null) {
            $this->error(sprintf('Competência "%s" inválida. Use o formato YYYY-MM.', $month));

            return self::FAILURE;
        }

        $value = (string) $this->option('value');

        if (! is_numeric($value) || (float) $value <= 0) {
            $this->error(sprintf('Número-índice "%s" inválido. Informe um valor positivo.', $value));

            return self::FAILURE;
        }

        $existing = IndexRate::query()
            ->where('indexer', PuIndexer::Ipca->value)
            ->whereDate('rate_date', $date->toDateString())
            ->first();

        if ($existing !== null) {
            $this->warn(sprintf(
                'Já existe um IPCA para %s (NI %s, fonte %s). Nada foi alterado.',
                $date->format('Y-m'),
                (string) $existing->rate_value,
                (string) $existing->source,
            ));

            return self::SUCCESS;
        }

        IndexRate::query()->create([
            'indexer' => PuIndexer::Ipca->value,
            'rate_date' => $date,
            'rate_value' => $value,
            'source' => (string) $this->option('source'),
            'is_projected' => false,
        ]);

        $this->info(sprintf('Número-índice âncora de IPCA criado: %s = %s.', $date->format('Y-m'), $value));

        return self::SUCCESS;
    }
}
