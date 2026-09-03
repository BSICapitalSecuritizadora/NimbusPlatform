<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateExternalValidationResult;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationDecision;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use Illuminate\Support\Facades\DB;

final class PuCandidateExternalValidationService
{
    public const ACTION_READY = 'ready_for_external_validation_review';

    public const ACTION_VALIDATED = 'candidate_external_validation_validated';

    public const ACTION_REJECTED = 'candidate_external_validation_rejected';

    public const ACTION_ALREADY_VALIDATED = 'already_validated';

    public const ACTION_ALREADY_REJECTED = 'already_rejected';

    public const ACTION_NOT_FOUND = 'external_validation_not_found';

    public const ACTION_NOT_READY = 'external_validation_not_ready';

    public const ACTION_REASON_REQUIRED = 'external_validation_rejection_reason_required';

    public const ACTION_REVIEW_CONFLICT = 'external_validation_decision_conflict';

    public const ACTION_INTEGRITY_FAILURE = 'external_validation_integrity_failure';

    public const ACTION_REVIEWER_REQUIRED = 'external_validation_reviewer_required';

    public const ACTION_REVIEWER_NOT_FOUND = 'external_validation_reviewer_not_found';

    public const ACTION_REVIEWER_INACTIVE = 'external_validation_reviewer_inactive';

    public const ACTION_REVIEWER_UNAPPROVED = 'external_validation_reviewer_unapproved';

    public const ACTION_REVIEWER_UNAUTHORIZED = 'external_validation_reviewer_unauthorized';

    public const ACTION_INDEPENDENCE_VIOLATION = 'external_validation_independence_violation';

    public function __construct(
        private readonly PuCandidateExternalValidationEligibilityService $eligibility,
        private readonly PuExternalBenchmarkIntegrityService $benchmarkIntegrity,
        private readonly PuExternalComparisonIntegrityService $comparisonIntegrity,
        private readonly PuExternalValidationActorService $actors,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * Preflight sem escrita. Quando um revisor explícito é informado, a
     * autorização e a independência também são verificadas aqui para que o
     * dry-run não aprove um cenário que a decisão real recusaria.
     */
    public function inspect(
        ?EmissionPuExternalValidation $validation,
        PuExternalValidationDecision $decision,
        ?string $reason = null,
        ?string $reviewerIdentifier = null,
    ): PuCandidateExternalValidationResult {
        if (! $validation instanceof EmissionPuExternalValidation) {
            return new PuCandidateExternalValidationResult(
                action: self::ACTION_NOT_FOUND,
                reason: 'The external validation dossier does not exist.',
                decision: $decision->value,
            );
        }

        if ($validation->status === PuCurveExternalValidationStatus::Validated) {
            return $this->result(
                $validation,
                $decision,
                $decision === PuExternalValidationDecision::Validate
                    ? self::ACTION_ALREADY_VALIDATED
                    : self::ACTION_REVIEW_CONFLICT,
                $decision === PuExternalValidationDecision::Validate
                    ? 'This dossier is already validated; no write will be repeated.'
                    : 'A final validated decision cannot be replaced by rejection.',
            );
        }

        if ($validation->status === PuCurveExternalValidationStatus::Rejected) {
            return $this->result(
                $validation,
                $decision,
                $decision === PuExternalValidationDecision::Reject
                    ? self::ACTION_ALREADY_REJECTED
                    : self::ACTION_REVIEW_CONFLICT,
                $decision === PuExternalValidationDecision::Reject
                    ? 'This dossier is already rejected; no write will be repeated.'
                    : 'A final rejected decision cannot be replaced by validation.',
            );
        }

        if ($decision === PuExternalValidationDecision::Reject && trim((string) $reason) === '') {
            return $this->result(
                $validation,
                $decision,
                self::ACTION_REASON_REQUIRED,
                'External validation rejection requires a non-empty reason.',
            );
        }

        $candidate = $validation->relationLoaded('candidate')
            ? $validation->candidate
            : $validation->candidate()->first();
        $benchmark = $validation->relationLoaded('benchmark')
            ? $validation->benchmark
            : $validation->benchmark()->first();
        $eligibility = $this->eligibility->inspect($candidate);

        if (! $eligibility['ready']
            || ! $candidate instanceof EmissionPuCurveVersion
            || ! $benchmark instanceof EmissionPuExternalBenchmark) {
            return $this->result(
                $validation,
                $decision,
                $eligibility['action'] === PuCandidateExternalValidationEligibilityService::ACTION_INTEGRITY_FAILURE
                    ? self::ACTION_INTEGRITY_FAILURE
                    : self::ACTION_NOT_READY,
                $benchmark instanceof EmissionPuExternalBenchmark
                    ? $eligibility['reason']
                    : 'The benchmark linked to this validation no longer exists.',
            );
        }

        if (! hash_equals($validation->candidate_checksum, (string) $candidate->curve_checksum)) {
            return $this->result(
                $validation,
                $decision,
                self::ACTION_INTEGRITY_FAILURE,
                'The comparison candidate checksum no longer matches the candidate dossier.',
            );
        }

        $benchmarkIntegrity = $this->benchmarkIntegrity->inspect($benchmark);

        if (! $benchmarkIntegrity['valid']
            || ! hash_equals($validation->benchmark_dataset_sha256, $benchmark->dataset_sha256)) {
            return $this->result(
                $validation,
                $decision,
                self::ACTION_INTEGRITY_FAILURE,
                'The comparison benchmark checksum no longer matches the immutable benchmark dataset.',
            );
        }

        $comparisonIntegrity = $this->comparisonIntegrity->inspect($validation);

        if (! $comparisonIntegrity['valid']) {
            return $this->result(
                $validation,
                $decision,
                self::ACTION_INTEGRITY_FAILURE,
                $comparisonIntegrity['reason'],
            );
        }

        if (trim((string) $reviewerIdentifier) !== '') {
            $reviewerResolution = $this->actors->resolve($reviewerIdentifier);

            if (! $reviewerResolution->resolved() || $reviewerResolution->actor === null) {
                return $this->reviewerFailure(
                    $validation,
                    $decision,
                    $reviewerResolution->failure,
                    $reviewerResolution->reason,
                );
            }

            if ($this->violatesIndependence($candidate, $reviewerResolution->actor->id)) {
                return $this->result(
                    $validation,
                    $decision,
                    self::ACTION_INDEPENDENCE_VIOLATION,
                    'The external reviewer must differ from both the candidate maker and the internal reviewer.',
                    reviewerId: $reviewerResolution->actor->id,
                );
            }

            return $this->result(
                $validation,
                $decision,
                self::ACTION_READY,
                'Candidate, benchmark, comparison and the independent reviewer are ready for a final decision.',
                reviewerId: $reviewerResolution->actor->id,
            );
        }

        return $this->result(
            $validation,
            $decision,
            self::ACTION_READY,
            'Candidate, benchmark and exact-date comparison are intact and ready for independent review.',
        );
    }

    public function write(
        ?EmissionPuExternalValidation $validation,
        PuExternalValidationDecision $decision,
        ?string $reviewerIdentifier,
        ?string $reason = null,
    ): PuCandidateExternalValidationResult {
        if ($validation instanceof EmissionPuExternalValidation) {
            $validation = $validation->fresh() ?? $validation;
        }

        $inspection = $this->inspect($validation, $decision, $reason);

        if (! $validation instanceof EmissionPuExternalValidation
            || $inspection->action !== self::ACTION_READY) {
            return $inspection;
        }

        $reviewerResolution = $this->actors->resolve($reviewerIdentifier);

        if (! $reviewerResolution->resolved()) {
            return $this->reviewerFailure($validation, $decision, $reviewerResolution->failure, $reviewerResolution->reason);
        }

        return DB::transaction(function () use (
            $validation,
            $decision,
            $reviewerIdentifier,
            $reason,
        ): PuCandidateExternalValidationResult {
            $lockedCandidate = EmissionPuCurveVersion::query()
                ->whereKey($validation->candidate_curve_version_id)
                ->lockForUpdate()
                ->first();
            $lockedBenchmark = EmissionPuExternalBenchmark::query()
                ->whereKey($validation->benchmark_id)
                ->lockForUpdate()
                ->first();
            $lockedValidation = EmissionPuExternalValidation::query()
                ->whereKey($validation->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedValidation instanceof EmissionPuExternalValidation) {
                return new PuCandidateExternalValidationResult(
                    action: self::ACTION_NOT_FOUND,
                    reason: 'The external validation dossier no longer exists.',
                    decision: $decision->value,
                );
            }

            $lockedValidation->setRelation('candidate', $lockedCandidate);
            $lockedValidation->setRelation('benchmark', $lockedBenchmark);
            $lockedInspection = $this->inspect($lockedValidation, $decision, $reason);

            if ($lockedInspection->action !== self::ACTION_READY) {
                return $lockedInspection;
            }

            $reviewerResolution = $this->actors->resolve($reviewerIdentifier, lockForUpdate: true);

            if (! $reviewerResolution->resolved() || $reviewerResolution->actor === null) {
                return $this->reviewerFailure(
                    $lockedValidation,
                    $decision,
                    $reviewerResolution->failure,
                    $reviewerResolution->reason,
                );
            }

            if (! $lockedCandidate instanceof EmissionPuCurveVersion) {
                return $this->result(
                    $lockedValidation,
                    $decision,
                    self::ACTION_NOT_READY,
                    'The candidate linked to this validation no longer exists.',
                );
            }

            if ($this->violatesIndependence($lockedCandidate, $reviewerResolution->actor->id)) {
                return $this->result(
                    $lockedValidation,
                    $decision,
                    self::ACTION_INDEPENDENCE_VIOLATION,
                    'The external reviewer must differ from both the candidate maker and the internal reviewer.',
                    reviewerId: $reviewerResolution->actor->id,
                );
            }

            $normalizedReason = trim((string) $reason);
            $status = $decision === PuExternalValidationDecision::Validate
                ? PuCurveExternalValidationStatus::Validated
                : PuCurveExternalValidationStatus::Rejected;
            $lockedValidation->forceFill([
                'status' => $status,
                'reviewed_by' => $reviewerResolution->actor->id,
                'reviewed_at' => now(),
                'review_reason' => $normalizedReason !== '' ? $normalizedReason : null,
            ])->save();
            $lockedCandidate->forceFill([
                'external_validation_status' => $status,
            ])->save();
            $this->auditLog->logCandidateExternalValidationDecision(
                validation: $lockedValidation,
                reviewer: $reviewerResolution->actor,
                decision: $decision,
            );

            return $this->result(
                $lockedValidation,
                $decision,
                $decision === PuExternalValidationDecision::Validate
                    ? self::ACTION_VALIDATED
                    : self::ACTION_REJECTED,
                $decision === PuExternalValidationDecision::Validate
                    ? 'The candidate was externally validated and remains a non-operational candidate.'
                    : 'The candidate was externally rejected and remains a non-operational candidate.',
                reviewerId: $reviewerResolution->actor->id,
                writes: 1,
            );
        });
    }

    /**
     * A validação externa só é independente quando o revisor não é nem o maker
     * da candidate nem o revisor interno que a aprovou.
     */
    private function violatesIndependence(EmissionPuCurveVersion $candidate, int $reviewerId): bool
    {
        return in_array($reviewerId, [
            (int) $candidate->generated_by,
            (int) $candidate->reviewed_by,
        ], true);
    }

    private function reviewerFailure(
        EmissionPuExternalValidation $validation,
        PuExternalValidationDecision $decision,
        ?string $failure,
        ?string $reason,
    ): PuCandidateExternalValidationResult {
        $action = match ($failure) {
            PuExternalValidationActorService::ACTOR_NOT_FOUND => self::ACTION_REVIEWER_NOT_FOUND,
            PuExternalValidationActorService::ACTOR_INACTIVE => self::ACTION_REVIEWER_INACTIVE,
            PuExternalValidationActorService::ACTOR_UNAPPROVED => self::ACTION_REVIEWER_UNAPPROVED,
            PuExternalValidationActorService::ACTOR_UNAUTHORIZED => self::ACTION_REVIEWER_UNAUTHORIZED,
            default => self::ACTION_REVIEWER_REQUIRED,
        };

        return $this->result(
            $validation,
            $decision,
            $action,
            $reason ?? 'An explicit authorized external reviewer is required.',
        );
    }

    private function result(
        EmissionPuExternalValidation $validation,
        PuExternalValidationDecision $decision,
        string $action,
        string $reason,
        ?int $reviewerId = null,
        int $writes = 0,
    ): PuCandidateExternalValidationResult {
        return new PuCandidateExternalValidationResult(
            action: $action,
            reason: $reason,
            externalValidationId: $validation->id,
            candidateVersionId: $validation->candidate_curve_version_id,
            benchmarkId: $validation->benchmark_id,
            decision: $decision->value,
            externalValidationStatus: $validation->status?->value,
            reviewerId: $reviewerId ?? $validation->reviewed_by,
            writes: $writes,
        );
    }
}
