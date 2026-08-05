<?php

namespace App\Console\Commands;

use App\Models\JobApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Elimina candidaturas antigas e os currículos correspondentes.
 *
 * Currículo é dado pessoal com finalidade esgotada quando o processo seletivo
 * termina; mantê-lo indefinidamente contraria os arts. 15 e 16 da LGPD e ainda
 * amplia o dano de um eventual vazamento.
 */
class PurgeJobApplications extends Command
{
    protected $signature = 'lgpd:purge-job-applications
        {--months= : Sobrescreve o prazo de retenção configurado}
        {--dry-run : Apenas relata o que seria eliminado}';

    protected $description = 'Elimina candidaturas e currículos além do prazo de retenção';

    public function handle(): int
    {
        $retentionMonths = (int) ($this->option('months') ?? config('privacy.retention.job_applications.months', 12));

        if ($retentionMonths <= 0) {
            $this->components->warn('Expurgo de candidaturas desativado (prazo de retenção não positivo).');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $cutoffDate = Carbon::now()->subMonths($retentionMonths)->startOfDay();

        $deletedApplications = 0;
        $deletedResumes = 0;

        JobApplication::query()
            ->where('created_at', '<', $cutoffDate)
            ->eachById(function (JobApplication $application) use ($isDryRun, &$deletedApplications, &$deletedResumes): void {
                $resumePath = (string) $application->resume_path;

                if ($resumePath !== '' && Storage::disk('resumes')->exists($resumePath)) {
                    if (! $isDryRun) {
                        Storage::disk('resumes')->delete($resumePath);
                    }

                    $deletedResumes++;
                }

                if (! $isDryRun) {
                    $application->delete();
                }

                $deletedApplications++;
            });

        // Registra só contagens: um log de expurgo que nomeasse os titulares
        // recriaria, no arquivo de log, o dado que a rotina acabou de eliminar.
        Log::info('Expurgo de candidaturas concluído.', [
            'retention_months' => $retentionMonths,
            'cutoff' => $cutoffDate->toDateString(),
            'applications' => $deletedApplications,
            'resumes' => $deletedResumes,
            'dry_run' => $isDryRun,
        ]);

        $this->components->info(sprintf(
            '%s %d candidatura(s) e %d currículo(s) anteriores a %s.',
            $isDryRun ? 'Seriam eliminadas' : 'Eliminadas',
            $deletedApplications,
            $deletedResumes,
            $cutoffDate->format('d/m/Y'),
        ));

        return self::SUCCESS;
    }
}
