<?php

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PuQueueHealthCommand extends Command
{
    protected $signature = 'pu:queue-health {--stale-minutes=30 : Minutos para considerar uma geracao travada}';

    protected $description = 'Verifica saude da fila usada pela calculadora de PU (jobs pendentes, falhos e versoes travadas).';

    public function handle(): int
    {
        $staleMinutes = (int) $this->option('stale-minutes');
        $hasProblem = false;

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $this->line(sprintf('Jobs pendentes na fila: %d', $pendingJobs));

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        if ($failedJobs > 0) {
            $hasProblem = true;
            $this->error(sprintf('Jobs falhos: %d (verifique `php artisan queue:failed`).', $failedJobs));
        } else {
            $this->line('Jobs falhos: 0');
        }

        $staleVersions = EmissionPuCurveVersion::query()
            ->where('status', PuCurveStatus::Processing->value)
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->get(['id', 'emission_id', 'calculation_version', 'updated_at']);

        if ($staleVersions->isNotEmpty()) {
            $hasProblem = true;
            $this->error(sprintf('Versoes em "processando" ha mais de %d min: %d', $staleMinutes, $staleVersions->count()));
            foreach ($staleVersions as $version) {
                $this->line(sprintf(
                    '  - Emissao #%d / %s (desde %s)',
                    $version->emission_id,
                    $version->calculation_version,
                    $version->updated_at?->format('d/m/Y H:i'),
                ));
            }
        } else {
            $this->line('Versoes travadas em processamento: 0');
        }

        if ($hasProblem) {
            $this->newLine();
            $this->warn('=> Problemas detectados. Garanta que ha um worker ativo (queue:work / Horizon / Supervisor).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=> Fila da calculadora de PU saudavel.');

        return self::SUCCESS;
    }
}
