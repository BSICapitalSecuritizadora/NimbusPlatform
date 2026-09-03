<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkImportResult;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuExternalBenchmarkStatus;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use Illuminate\Support\Facades\DB;

final class PuExternalBenchmarkImportService
{
    public const ACTION_IMPORTED = 'external_benchmark_imported';

    public function __construct(
        private readonly PuExternalBenchmarkImportPlanService $plans,
        private readonly PuCandidateExternalValidationEligibilityService $eligibility,
        private readonly PuExternalValidationActorService $actors,
        private readonly PuExternalBenchmarkFingerprintService $fingerprints,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function write(
        ?EmissionPuCurveVersion $candidate,
        string $path,
        string $sourceType,
        string $sourceName,
        string $referenceAsOf,
        ?int $sourceEvidenceId,
        ?string $actorIdentifier,
    ): PuExternalBenchmarkImportResult {
        $plan = $this->plans->plan(
            $candidate,
            $path,
            $sourceType,
            $sourceName,
            $referenceAsOf,
            $sourceEvidenceId,
        );

        if ($plan->action !== PuExternalBenchmarkImportPlanService::ACTION_READY
            || ! $candidate instanceof EmissionPuCurveVersion
            || $plan->dataset === null) {
            return $plan;
        }

        $actorResolution = $this->actors->resolve($actorIdentifier);

        if (! $actorResolution->resolved()) {
            return $this->actorFailure($plan, $actorResolution->failure, $actorResolution->reason);
        }

        return DB::transaction(function () use ($candidate, $plan, $actorIdentifier): PuExternalBenchmarkImportResult {
            $lockedCandidate = EmissionPuCurveVersion::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();
            $eligibility = $this->eligibility->inspect($lockedCandidate);

            if (! $eligibility['ready'] || ! $lockedCandidate instanceof EmissionPuCurveVersion) {
                return $this->copy($plan, $eligibility['action'], $eligibility['reason']);
            }

            $actorResolution = $this->actors->resolve($actorIdentifier, lockForUpdate: true);

            if (! $actorResolution->resolved() || $actorResolution->actor === null) {
                return $this->actorFailure($plan, $actorResolution->failure, $actorResolution->reason);
            }

            $sourceEvidence = $this->lockedSourceEvidence($lockedCandidate, $plan->sourceEvidenceId);

            if ($plan->sourceEvidenceId !== null && ! $sourceEvidence instanceof EmissionPuBaselineEvidence) {
                return $this->copy(
                    $plan,
                    PuExternalBenchmarkImportPlanService::ACTION_CONFLICT,
                    'The governed source evidence changed before import.',
                );
            }

            $identity = $this->fingerprints->importIdentity(
                emissionId: $lockedCandidate->emission_id,
                sourceType: (string) $plan->sourceType,
                sourceName: (string) $plan->sourceName,
                sourceEvidenceId: $sourceEvidence?->id,
                sourceDocumentId: $sourceEvidence?->document_id,
                referenceAsOf: (string) $plan->referenceAsOf,
                datasetSha256: $plan->dataset->datasetSha256,
            );
            $existing = EmissionPuExternalBenchmark::query()
                ->where('import_identity_sha256', $identity)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EmissionPuExternalBenchmark) {
                return $this->copy(
                    $plan,
                    PuExternalBenchmarkImportPlanService::ACTION_ALREADY_IMPORTED,
                    'The same governed semantic dataset was imported concurrently; no write was repeated.',
                    benchmarkId: $existing->id,
                );
            }

            $benchmark = EmissionPuExternalBenchmark::query()->create([
                'emission_id' => $lockedCandidate->emission_id,
                'source_type' => $plan->sourceType,
                'source_name' => $plan->sourceName,
                'source_document_id' => $sourceEvidence?->document_id,
                'source_evidence_id' => $sourceEvidence?->id,
                'reference_as_of' => $plan->referenceAsOf,
                'input_file_name' => $plan->dataset->inputFileName,
                'file_sha256' => $plan->dataset->fileSha256,
                'dataset_sha256' => $plan->dataset->datasetSha256,
                'import_identity_sha256' => $identity,
                'row_count' => count($plan->dataset->rows),
                'from_date' => $plan->dataset->fromDate,
                'to_date' => $plan->dataset->toDate,
                'status' => PuExternalBenchmarkStatus::Ready,
                'created_by' => $actorResolution->actor->id,
            ]);
            $timestamp = now();
            $rows = array_map(fn ($row): array => [
                'benchmark_id' => $benchmark->id,
                'reference_date' => $row->referenceDate->toDateString(),
                'unit_value' => $row->unitValue,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $plan->dataset->rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                EmissionPuExternalBenchmarkRow::query()->insert($chunk);
            }

            $this->auditLog->logExternalBenchmarkImported(
                candidate: $lockedCandidate,
                benchmark: $benchmark,
                actor: $actorResolution->actor,
            );

            return $this->copy(
                $plan,
                self::ACTION_IMPORTED,
                'The immutable machine-readable external PU benchmark was imported.',
                benchmarkId: $benchmark->id,
                writes: 1,
            );
        });
    }

    private function lockedSourceEvidence(
        EmissionPuCurveVersion $candidate,
        ?int $sourceEvidenceId,
    ): ?EmissionPuBaselineEvidence {
        if ($sourceEvidenceId === null) {
            return null;
        }

        return EmissionPuBaselineEvidence::query()
            ->whereKey($sourceEvidenceId)
            ->where('emission_id', $candidate->emission_id)
            ->where('evidence_type', PuBaselineEvidenceType::ExternalPuReference->value)
            ->where('status', PuBaselineEvidenceStatus::Approved->value)
            ->lockForUpdate()
            ->first();
    }

    private function actorFailure(
        PuExternalBenchmarkImportResult $plan,
        ?string $action,
        ?string $reason,
    ): PuExternalBenchmarkImportResult {
        return $this->copy(
            $plan,
            $action ?? PuExternalValidationActorService::ACTOR_REQUIRED,
            $reason ?? 'An explicit authorized actor is required.',
        );
    }

    private function copy(
        PuExternalBenchmarkImportResult $plan,
        string $action,
        string $reason,
        ?int $benchmarkId = null,
        int $writes = 0,
    ): PuExternalBenchmarkImportResult {
        return new PuExternalBenchmarkImportResult(
            action: $action,
            reason: $reason,
            candidateVersionId: $plan->candidateVersionId,
            benchmarkId: $benchmarkId ?? $plan->benchmarkId,
            sourceType: $plan->sourceType,
            sourceName: $plan->sourceName,
            referenceAsOf: $plan->referenceAsOf,
            sourceEvidenceId: $plan->sourceEvidenceId,
            sourceDocumentId: $plan->sourceDocumentId,
            dataset: $plan->dataset,
            coveragePreview: $plan->coveragePreview,
            candidateDatesWithoutReference: $plan->candidateDatesWithoutReference,
            referenceDatesWithoutCandidate: $plan->referenceDatesWithoutCandidate,
            writes: $writes,
        );
    }
}
