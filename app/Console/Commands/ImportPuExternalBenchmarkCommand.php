<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkImportResult;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkImportPlanService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkImportService;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ImportPuExternalBenchmarkCommand extends Command
{
    protected $signature = 'pu:curve-candidate:import-external-benchmark
                            {version : Candidate EmissionPuCurveVersion id}
                            {file : Local CSV or XLSX file containing Data and PU columns}
                            {--source-type=external_file : Stable governed source type}
                            {--source-name= : Human-readable external source identity}
                            {--as-of= : Benchmark reference date in YYYY-MM-DD}
                            {--evidence= : Optional approved ExternalPuReference evidence id}
                            {--actor= : Explicit authorized actor (user id or email), required for --write}
                            {--dry-run : Parse and plan without mutating (default)}
                            {--write : Persist the immutable benchmark after locked rechecks}';

    protected $description = 'Phase 2B.5.17 — Plan or import a governed machine-readable external PU benchmark';

    public function handle(
        PuExternalBenchmarkImportPlanService $plans,
        PuExternalBenchmarkImportService $imports,
    ): int {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run') || ! $write;

        if ($dryRun && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running read-only only.');
            $write = false;
        }

        try {
            $candidate = EmissionPuCurveVersion::query()->whereKey($this->positiveId('version'))->first();
            $sourceEvidenceId = filled($this->option('evidence'))
                ? $this->positiveId('evidence', option: true)
                : null;
            $arguments = [
                $candidate,
                (string) $this->argument('file'),
                (string) $this->option('source-type'),
                (string) $this->option('source-name'),
                (string) $this->option('as-of'),
                $sourceEvidenceId,
            ];
            $result = $write
                ? $imports->write(...[...$arguments, filled($this->option('actor')) ? (string) $this->option('actor') : null])
                : $plans->plan(...$arguments);
            $this->renderResult($result, $write);

            return in_array($result->action, [
                PuExternalBenchmarkImportPlanService::ACTION_READY,
                PuExternalBenchmarkImportPlanService::ACTION_ALREADY_IMPORTED,
                PuExternalBenchmarkImportService::ACTION_IMPORTED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function positiveId(string $name, bool $option = false): int
    {
        $value = trim((string) ($option ? $this->option($name) : $this->argument($name)));

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("{$name} must be a positive id.");
        }

        return (int) $value;
    }

    private function renderResult(PuExternalBenchmarkImportResult $result, bool $write): void
    {
        $this->components->info('Phase 2B.5.17 — External PU Benchmark '.($write ? 'Import' : 'Dry-run'));
        $this->line('Candidate: '.($result->candidateVersionId === null ? 'not available' : '#'.$result->candidateVersionId));
        $this->line('Benchmark: '.($result->benchmarkId === null ? 'not persisted' : '#'.$result->benchmarkId));
        $this->line('Source: '.($result->sourceName ?? 'not available').' ['.($result->sourceType ?? 'not available').']');
        $this->line('Reference as-of: '.($result->referenceAsOf ?? 'not available'));
        $this->line('Input file: '.($result->dataset?->inputFileName ?? 'not parsed'));
        $this->line('File SHA-256: '.($result->dataset?->fileSha256 ?? 'not available'));
        $this->line('Dataset SHA-256: '.($result->dataset?->datasetSha256 ?? 'not available'));
        $this->line('Parsed rows: '.count($result->dataset?->rows ?? []));
        $this->line('Dataset window: '.($result->dataset === null
            ? 'not available'
            : $result->dataset->fromDate.' to '.$result->dataset->toDate));
        $this->line('Coverage preview: '.($result->coveragePreview ?? 'not evaluated'));
        $this->line('Candidate dates without reference: '.count($result->candidateDatesWithoutReference));
        $this->line('Reference dates without candidate: '.count($result->referenceDatesWithoutCandidate));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('No comparison decision or operational promotion is performed by this command.');
    }
}
