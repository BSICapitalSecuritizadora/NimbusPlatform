<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkComparisonResult;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationGap;
use App\Models\EmissionPuExternalValidationRow;
use Illuminate\Support\Facades\DB;

final class PuExternalBenchmarkComparisonService
{
    public const ACTION_CREATED = 'pu_external_comparison_created';

    public function __construct(
        private readonly PuExternalBenchmarkComparisonPlanService $plans,
        private readonly PuCandidateExternalValidationEligibilityService $eligibility,
        private readonly PuExternalBenchmarkIntegrityService $benchmarkIntegrity,
        private readonly PuExternalComparisonIntegrityService $comparisonIntegrity,
        private readonly PuExternalValidationActorService $actors,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function write(
        ?EmissionPuCurveVersion $candidate,
        ?EmissionPuExternalBenchmark $benchmark,
        ?string $actorIdentifier,
    ): PuExternalBenchmarkComparisonResult {
        $plan = $this->plans->plan($candidate, $benchmark);

        if ($plan->action !== PuExternalBenchmarkComparisonPlanService::ACTION_READY
            || ! $candidate instanceof EmissionPuCurveVersion
            || ! $benchmark instanceof EmissionPuExternalBenchmark) {
            return $plan;
        }

        $actorResolution = $this->actors->resolve($actorIdentifier);

        if (! $actorResolution->resolved()) {
            return $this->copy(
                $plan,
                $actorResolution->failure ?? PuExternalValidationActorService::ACTOR_REQUIRED,
                $actorResolution->reason ?? 'An explicit authorized actor is required.',
            );
        }

        return DB::transaction(function () use (
            $candidate,
            $benchmark,
            $actorIdentifier,
            $plan,
        ): PuExternalBenchmarkComparisonResult {
            $lockedCandidate = EmissionPuCurveVersion::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();
            $lockedBenchmark = EmissionPuExternalBenchmark::query()
                ->whereKey($benchmark->id)
                ->lockForUpdate()
                ->first();
            $eligibility = $this->eligibility->inspect($lockedCandidate);

            if (! $eligibility['ready'] || ! $lockedCandidate instanceof EmissionPuCurveVersion) {
                return $this->copy($plan, $eligibility['action'], $eligibility['reason']);
            }

            if (! $lockedBenchmark instanceof EmissionPuExternalBenchmark
                || $lockedBenchmark->emission_id !== $lockedCandidate->emission_id) {
                return $this->copy(
                    $plan,
                    PuExternalBenchmarkComparisonPlanService::ACTION_BENCHMARK_MISMATCH,
                    'The external benchmark disappeared or no longer matches the candidate emission.',
                );
            }

            $benchmarkIntegrity = $this->benchmarkIntegrity->inspect($lockedBenchmark);

            if (! $benchmarkIntegrity['valid']
                || ! hash_equals((string) $plan->candidateChecksum, (string) $lockedCandidate->curve_checksum)
                || ! hash_equals((string) $plan->benchmarkDatasetSha256, $lockedBenchmark->dataset_sha256)) {
                return $this->copy(
                    $plan,
                    PuExternalBenchmarkComparisonPlanService::ACTION_CONFLICT,
                    'Candidate or benchmark integrity changed after the comparison preflight.',
                );
            }

            $actorResolution = $this->actors->resolve($actorIdentifier, lockForUpdate: true);

            if (! $actorResolution->resolved() || $actorResolution->actor === null) {
                return $this->copy(
                    $plan,
                    $actorResolution->failure ?? PuExternalValidationActorService::ACTOR_REQUIRED,
                    $actorResolution->reason ?? 'An explicit authorized actor is required.',
                );
            }

            $existing = EmissionPuExternalValidation::query()
                ->where('candidate_curve_version_id', $lockedCandidate->id)
                ->where('benchmark_id', $lockedBenchmark->id)
                ->where('comparison_algorithm_version', $plan->comparisonAlgorithmVersion)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EmissionPuExternalValidation) {
                $integrity = $this->comparisonIntegrity->inspect($existing);
                $same = $integrity['valid']
                    && hash_equals($existing->comparison_sha256, (string) $plan->comparisonSha256);

                return $this->copy(
                    $plan,
                    $same
                        ? PuExternalBenchmarkComparisonPlanService::ACTION_ALREADY_COMPARED
                        : PuExternalBenchmarkComparisonPlanService::ACTION_CONFLICT,
                    $same
                        ? 'The comparison was created concurrently; no write was repeated.'
                        : 'A conflicting comparison already uses this semantic identity.',
                    externalValidationId: $existing->id,
                );
            }

            $validation = EmissionPuExternalValidation::query()->create([
                'candidate_curve_version_id' => $lockedCandidate->id,
                'benchmark_id' => $lockedBenchmark->id,
                'candidate_checksum' => $plan->candidateChecksum,
                'benchmark_dataset_sha256' => $plan->benchmarkDatasetSha256,
                'comparison_algorithm_version' => $plan->comparisonAlgorithmVersion,
                'comparison_sha256' => $plan->comparisonSha256,
                'coverage_status' => $plan->coverageStatus,
                'compared_rows' => $plan->comparedRows,
                'candidate_dates_without_reference' => $plan->candidateDatesWithoutReference,
                'reference_dates_without_candidate' => $plan->referenceDatesWithoutCandidate,
                'status' => PuCurveExternalValidationStatus::Pending,
                'generated_by' => $actorResolution->actor->id,
            ]);
            $timestamp = now();
            $differenceRows = array_map(fn (array $difference): array => [
                'external_validation_id' => $validation->id,
                ...$difference,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $plan->differences);
            $gapRows = array_map(fn (array $gap): array => [
                'external_validation_id' => $validation->id,
                ...$gap,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $plan->gaps);

            foreach (array_chunk($differenceRows, 500) as $chunk) {
                EmissionPuExternalValidationRow::query()->insert($chunk);
            }

            foreach (array_chunk($gapRows, 500) as $chunk) {
                EmissionPuExternalValidationGap::query()->insert($chunk);
            }

            $this->auditLog->logExternalComparisonCreated(
                validation: $validation,
                actor: $actorResolution->actor,
            );

            return $this->copy(
                $plan,
                self::ACTION_CREATED,
                'The reproducible external validation dossier was created and remains pending human review.',
                externalValidationId: $validation->id,
                writes: 1,
            );
        });
    }

    private function copy(
        PuExternalBenchmarkComparisonResult $plan,
        string $action,
        string $reason,
        ?int $externalValidationId = null,
        int $writes = 0,
    ): PuExternalBenchmarkComparisonResult {
        return new PuExternalBenchmarkComparisonResult(
            action: $action,
            reason: $reason,
            candidateVersionId: $plan->candidateVersionId,
            benchmarkId: $plan->benchmarkId,
            externalValidationId: $externalValidationId ?? $plan->externalValidationId,
            candidateChecksum: $plan->candidateChecksum,
            benchmarkDatasetSha256: $plan->benchmarkDatasetSha256,
            comparisonAlgorithmVersion: $plan->comparisonAlgorithmVersion,
            comparisonSha256: $plan->comparisonSha256,
            coverageStatus: $plan->coverageStatus,
            comparedRows: $plan->comparedRows,
            candidateDatesWithoutReference: $plan->candidateDatesWithoutReference,
            referenceDatesWithoutCandidate: $plan->referenceDatesWithoutCandidate,
            differences: $plan->differences,
            gaps: $plan->gaps,
            maximumAbsoluteDifference: $plan->maximumAbsoluteDifference,
            maximumRelativeDifference: $plan->maximumRelativeDifference,
            tolerancePolicy: null,
            writes: $writes,
        );
    }
}
