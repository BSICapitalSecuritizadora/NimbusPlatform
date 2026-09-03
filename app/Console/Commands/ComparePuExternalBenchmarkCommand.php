<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkComparisonResult;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkComparisonPlanService;
use App\Domain\PuCalculator\Services\PuExternalBenchmarkComparisonService;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ComparePuExternalBenchmarkCommand extends Command
{
    protected $signature = 'pu:curve-candidate:compare-external-benchmark
                            {version : Candidate EmissionPuCurveVersion id}
                            {benchmark : EmissionPuExternalBenchmark id}
                            {--actor= : Explicit authorized actor (user id or email), required for --write}
                            {--dry-run : Compute a read-only deterministic comparison (default)}
                            {--write : Persist the immutable comparison dossier}';

    protected $description = 'Phase 2B.5.17 — Compare a PU candidate to an imported external benchmark by exact date';

    public function handle(
        PuExternalBenchmarkComparisonPlanService $plans,
        PuExternalBenchmarkComparisonService $comparisons,
    ): int {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run') || ! $write;

        if ($dryRun && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running read-only only.');
            $write = false;
        }

        try {
            $candidate = EmissionPuCurveVersion::query()->whereKey($this->positiveId('version'))->first();
            $benchmark = EmissionPuExternalBenchmark::query()->whereKey($this->positiveId('benchmark'))->first();
            $result = $write
                ? $comparisons->write(
                    $candidate,
                    $benchmark,
                    filled($this->option('actor')) ? (string) $this->option('actor') : null,
                )
                : $plans->plan($candidate, $benchmark);
            $this->renderResult($result, $write);

            return in_array($result->action, [
                PuExternalBenchmarkComparisonPlanService::ACTION_READY,
                PuExternalBenchmarkComparisonPlanService::ACTION_ALREADY_COMPARED,
                PuExternalBenchmarkComparisonService::ACTION_CREATED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function positiveId(string $argument): int
    {
        $value = trim((string) $this->argument($argument));

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("{$argument} must be a positive id.");
        }

        return (int) $value;
    }

    private function renderResult(PuExternalBenchmarkComparisonResult $result, bool $write): void
    {
        $this->components->info('Phase 2B.5.17 — External PU Comparison '.($write ? 'Write' : 'Dry-run'));
        $this->line('Candidate: '.($result->candidateVersionId === null ? 'not available' : '#'.$result->candidateVersionId));
        $this->line('Benchmark: '.($result->benchmarkId === null ? 'not available' : '#'.$result->benchmarkId));
        $this->line('Validation dossier: '.($result->externalValidationId === null ? 'not persisted' : '#'.$result->externalValidationId));
        $this->line('Candidate checksum: '.($result->candidateChecksum ?? 'not available'));
        $this->line('Benchmark checksum: '.($result->benchmarkDatasetSha256 ?? 'not available'));
        $this->line('Comparison checksum: '.($result->comparisonSha256 ?? 'not available'));
        $this->line('Algorithm: '.($result->comparisonAlgorithmVersion ?? 'not available'));
        $this->line('Coverage: '.($result->coverageStatus ?? 'not available'));
        $this->line('Compared rows: '.$result->comparedRows);
        $this->line('Candidate dates without reference: '.$result->candidateDatesWithoutReference);
        $this->line('Reference dates without candidate: '.$result->referenceDatesWithoutCandidate);
        $this->line('Tolerance policy: '.($result->tolerancePolicy ?? 'null (reported_without_tolerance)'));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('No automatic validation and no operational effect occurred.');
    }
}
