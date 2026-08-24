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
 *
 * Suporta overrides por status terminal via `privacy.retention.retention_overrides`.
 */
class PurgeJobApplications extends Command
{
    protected $signature = 'lgpd:purge-job-applications
        {--months= : Sobrescreve o prazo de retenção configurado}
        {--dry-run : Apenas relata o que seria eliminado}';

    protected $description = 'Elimina candidaturas e currículos além do prazo de retenção';

    public function handle(): int
    {
        $globalRetentionMonths = (int) ($this->option('months') ?? config('privacy.retention.job_applications.months', 12));

        // If --months is given, it overrides everything globally.
        $overrides = $this->option('months') !== null ? [] : (config('privacy.retention.retention_overrides.job_applications', []) ?? []);

        // Normalize overrides: filter nulls and non-positives
        $overrides = collect($overrides)
            ->filter(fn ($v): bool => $v !== null && (int) $v > 0)
            ->map(fn ($v): int => (int) $v)
            ->all();

        if ($globalRetentionMonths <= 0 && empty($overrides)) {
            $this->components->warn('Expurgo de candidaturas desativado (prazo de retenção não positivo).');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $deletedApplications = 0;
        $deletedResumes = 0;

        JobApplication::query()
            ->eachById(function (JobApplication $application) use ($isDryRun, $globalRetentionMonths, $overrides, &$deletedApplications, &$deletedResumes): void {
                $retention = $globalRetentionMonths;

                if (isset($overrides[$application->status])) {
                    $retention = (int) $overrides[$application->status];
                }

                if ($retention <= 0) {
                    return;
                }

                $cutoffDate = Carbon::now()->subMonths($retention)->startOfDay();

                if ($application->created_at >= $cutoffDate) {
                    return;
                }

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

        Log::info('Expurgo de candidaturas concluído.', [
            'retention_months' => $globalRetentionMonths,
            'overrides' => $overrides,
            'applications' => $deletedApplications,
            'resumes' => $deletedResumes,
            'dry_run' => $isDryRun,
        ]);

        $this->components->info(sprintf(
            '%s %d candidatura(s) e %d currículo(s) conforme regras de retenção.',
            $isDryRun ? 'Seriam eliminadas' : 'Eliminadas',
            $deletedApplications,
            $deletedResumes,
        ));

        if (! empty($overrides)) {
            $this->components->info('Overrides ativos: '.json_encode($overrides));
        }

        return self::SUCCESS;
    }
}
