<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkImportResult;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuExternalValidationCoverageStatus;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class PuExternalBenchmarkImportPlanService
{
    public const ACTION_INVALID = 'invalid';

    public const ACTION_READY = 'ready_to_import';

    public const ACTION_ALREADY_IMPORTED = 'already_imported';

    public const ACTION_CONFLICT = 'conflict';

    public function __construct(
        private readonly PuCandidateExternalValidationEligibilityService $eligibility,
        private readonly PuExternalBenchmarkFileParser $parser,
        private readonly PuExternalBenchmarkFingerprintService $fingerprints,
    ) {}

    public function plan(
        ?EmissionPuCurveVersion $candidate,
        string $path,
        string $sourceType,
        string $sourceName,
        string $referenceAsOf,
        ?int $sourceEvidenceId = null,
    ): PuExternalBenchmarkImportResult {
        $eligibility = $this->eligibility->inspect($candidate);

        if (! $eligibility['ready'] || ! $candidate instanceof EmissionPuCurveVersion) {
            return new PuExternalBenchmarkImportResult(
                action: $eligibility['action'],
                reason: $eligibility['reason'],
                candidateVersionId: $candidate?->id,
            );
        }

        $sourceType = Str::of($sourceType)->trim()->lower()->toString();
        $sourceName = Str::of($sourceName)->squish()->toString();
        $referenceDate = $this->referenceDate($referenceAsOf);

        if (preg_match('/^[a-z0-9_]{1,50}$/', $sourceType) !== 1) {
            return $this->invalid($candidate, 'Source type must contain only lowercase letters, numbers and underscores.');
        }

        if ($sourceName === '' || Str::length($sourceName) > 255) {
            return $this->invalid($candidate, 'Source name is required and may contain at most 255 characters.');
        }

        if (! $referenceDate instanceof CarbonImmutable) {
            return $this->invalid($candidate, 'Reference as-of must be a real date in YYYY-MM-DD format.');
        }

        $evidence = $this->sourceEvidence($candidate, $sourceEvidenceId);

        if ($sourceEvidenceId !== null && ! $evidence instanceof EmissionPuBaselineEvidence) {
            return $this->invalid(
                $candidate,
                'Source evidence must be an approved external PU reference document for the same emission.',
            );
        }

        try {
            $dataset = $this->parser->parse($path);
        } catch (Throwable $exception) {
            return $this->invalid($candidate, $exception->getMessage());
        }

        $candidateDates = $candidate->dailyCurves()
            ->orderBy('curve_date')
            ->get(['curve_date'])
            ->map(fn ($row): string => $row->curve_date->toDateString())
            ->all();
        $referenceDates = array_map(
            fn ($row): string => $row->referenceDate->toDateString(),
            $dataset->rows,
        );
        $candidateDatesWithoutReference = array_values(array_diff($candidateDates, $referenceDates));
        $referenceDatesWithoutCandidate = array_values(array_diff($referenceDates, $candidateDates));
        $comparedRows = count(array_intersect($candidateDates, $referenceDates));
        $coverage = $this->coverage(
            $comparedRows,
            $candidateDatesWithoutReference,
            $referenceDatesWithoutCandidate,
        );
        $identity = $this->fingerprints->importIdentity(
            emissionId: $candidate->emission_id,
            sourceType: $sourceType,
            sourceName: $sourceName,
            sourceEvidenceId: $evidence?->id,
            sourceDocumentId: $evidence?->document_id,
            referenceAsOf: $referenceDate->toDateString(),
            datasetSha256: $dataset->datasetSha256,
        );
        $existing = EmissionPuExternalBenchmark::query()
            ->where('import_identity_sha256', $identity)
            ->first();

        if ($existing instanceof EmissionPuExternalBenchmark) {
            return new PuExternalBenchmarkImportResult(
                action: self::ACTION_ALREADY_IMPORTED,
                reason: 'The same governed semantic dataset is already imported; no write is required.',
                candidateVersionId: $candidate->id,
                benchmarkId: $existing->id,
                sourceType: $sourceType,
                sourceName: $sourceName,
                referenceAsOf: $referenceDate->toDateString(),
                sourceEvidenceId: $evidence?->id,
                sourceDocumentId: $evidence?->document_id,
                dataset: $dataset,
                coveragePreview: $coverage->value,
                candidateDatesWithoutReference: $candidateDatesWithoutReference,
                referenceDatesWithoutCandidate: $referenceDatesWithoutCandidate,
            );
        }

        if ($coverage === PuExternalValidationCoverageStatus::None) {
            return new PuExternalBenchmarkImportResult(
                action: self::ACTION_INVALID,
                reason: 'The external benchmark has no exact-date overlap with the candidate horizon.',
                candidateVersionId: $candidate->id,
                sourceType: $sourceType,
                sourceName: $sourceName,
                referenceAsOf: $referenceDate->toDateString(),
                sourceEvidenceId: $evidence?->id,
                sourceDocumentId: $evidence?->document_id,
                dataset: $dataset,
                coveragePreview: $coverage->value,
                candidateDatesWithoutReference: $candidateDatesWithoutReference,
                referenceDatesWithoutCandidate: $referenceDatesWithoutCandidate,
            );
        }

        return new PuExternalBenchmarkImportResult(
            action: self::ACTION_READY,
            reason: 'The external benchmark is valid and ready for an explicit append-only import.',
            candidateVersionId: $candidate->id,
            sourceType: $sourceType,
            sourceName: $sourceName,
            referenceAsOf: $referenceDate->toDateString(),
            sourceEvidenceId: $evidence?->id,
            sourceDocumentId: $evidence?->document_id,
            dataset: $dataset,
            coveragePreview: $coverage->value,
            candidateDatesWithoutReference: $candidateDatesWithoutReference,
            referenceDatesWithoutCandidate: $referenceDatesWithoutCandidate,
        );
    }

    private function referenceDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }

    private function sourceEvidence(
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
            ->first();
    }

    /**
     * @param  list<string>  $candidateGaps
     * @param  list<string>  $referenceGaps
     */
    private function coverage(int $comparedRows, array $candidateGaps, array $referenceGaps): PuExternalValidationCoverageStatus
    {
        if ($comparedRows === 0) {
            return PuExternalValidationCoverageStatus::None;
        }

        return $candidateGaps === [] && $referenceGaps === []
            ? PuExternalValidationCoverageStatus::Full
            : PuExternalValidationCoverageStatus::Partial;
    }

    private function invalid(EmissionPuCurveVersion $candidate, string $reason): PuExternalBenchmarkImportResult
    {
        return new PuExternalBenchmarkImportResult(
            action: self::ACTION_INVALID,
            reason: $reason,
            candidateVersionId: $candidate->id,
        );
    }
}
