<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateCurveReviewResult;
use App\Domain\PuCalculator\Enums\PuCandidateReviewDecision;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Enums\AccessPermission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PuCandidateCurveReviewService
{
    public const ACTION_READY = 'ready_for_candidate_review';

    public const ACTION_APPROVED = 'candidate_approved';

    public const ACTION_REJECTED = 'candidate_rejected';

    public const ACTION_ALREADY_APPROVED = 'already_approved';

    public const ACTION_ALREADY_REJECTED = 'already_rejected';

    public const ACTION_NOT_FOUND = 'candidate_not_found';

    public const ACTION_NOT_REVIEWABLE = 'candidate_not_reviewable';

    public const ACTION_REASON_REQUIRED = 'candidate_rejection_reason_required';

    public const ACTION_REVIEW_CONFLICT = 'candidate_review_conflict';

    public const ACTION_MAKER_CHECKER_VIOLATION = 'candidate_maker_checker_violation';

    public const ACTION_REVIEWER_REQUIRED = 'candidate_reviewer_required';

    public const ACTION_REVIEWER_NOT_FOUND = 'candidate_reviewer_not_found';

    public const ACTION_REVIEWER_INACTIVE = 'candidate_reviewer_inactive';

    public const ACTION_REVIEWER_UNAPPROVED = 'candidate_reviewer_unapproved';

    public const ACTION_REVIEWER_UNAUTHORIZED = 'candidate_reviewer_unauthorized';

    public function __construct(
        private readonly PuPersistedCurveChecksumService $checksums,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function inspect(
        ?EmissionPuCurveVersion $version,
        PuCandidateReviewDecision $decision,
        ?string $reason = null,
    ): PuCandidateCurveReviewResult {
        if (! $version instanceof EmissionPuCurveVersion) {
            return new PuCandidateCurveReviewResult(
                action: self::ACTION_NOT_FOUND,
                reason: 'A candidate informada não existe.',
                decision: $decision->value,
            );
        }

        if (! $version->isCandidate()
            || $version->status !== PuCurveStatus::Validated
            || $version->internal_validation_status !== PuCurveInternalValidationStatus::Passed) {
            return $this->result(
                $version,
                $decision,
                self::ACTION_NOT_REVIEWABLE,
                'Somente candidate validada internamente e ainda não operacional pode ser revisada.',
            );
        }

        $reviewStatus = $version->review_status;

        if ($reviewStatus === PuCurveReviewStatus::Approved) {
            return $this->result(
                $version,
                $decision,
                $decision === PuCandidateReviewDecision::Approve
                    ? self::ACTION_ALREADY_APPROVED
                    : self::ACTION_REVIEW_CONFLICT,
                $decision === PuCandidateReviewDecision::Approve
                    ? 'A candidate já foi aprovada; nenhum side effect será repetido.'
                    : 'A decisão final de aprovação é imutável; gere nova candidate para nova tentativa.',
            );
        }

        if ($reviewStatus === PuCurveReviewStatus::Rejected) {
            return $this->result(
                $version,
                $decision,
                $decision === PuCandidateReviewDecision::Reject
                    ? self::ACTION_ALREADY_REJECTED
                    : self::ACTION_REVIEW_CONFLICT,
                $decision === PuCandidateReviewDecision::Reject
                    ? 'A candidate já foi rejeitada; nenhum side effect será repetido.'
                    : 'A decisão final de rejeição é imutável; gere nova candidate para nova tentativa.',
            );
        }

        if ($reviewStatus !== PuCurveReviewStatus::PendingReview) {
            return $this->result(
                $version,
                $decision,
                self::ACTION_NOT_REVIEWABLE,
                'O workflow de review da candidate não está pendente.',
            );
        }

        if ($decision === PuCandidateReviewDecision::Reject && ! filled(trim((string) $reason))) {
            return $this->result(
                $version,
                $decision,
                self::ACTION_REASON_REQUIRED,
                'A rejeição exige motivo não vazio.',
            );
        }

        if ($version->dailyCurves()->count() !== $version->rows_count
            || $version->curve_checksum === null
            || ! hash_equals($version->curve_checksum, $this->checksums->checksum($version))) {
            return $this->result(
                $version,
                $decision,
                self::ACTION_NOT_REVIEWABLE,
                'A integridade persistida da candidate não confere com o dossiê.',
            );
        }

        return $this->result(
            $version,
            $decision,
            self::ACTION_READY,
            'A candidate está íntegra e pronta para decisão maker-checker.',
        );
    }

    public function write(
        ?EmissionPuCurveVersion $version,
        PuCandidateReviewDecision $decision,
        ?string $reviewerIdentifier,
        ?string $reason = null,
    ): PuCandidateCurveReviewResult {
        $inspection = $this->inspect($version, $decision, $reason);

        if (! $version instanceof EmissionPuCurveVersion
            || $inspection->action !== self::ACTION_READY) {
            return $inspection;
        }

        if (! filled($reviewerIdentifier)) {
            return $this->result(
                $version,
                $decision,
                self::ACTION_REVIEWER_REQUIRED,
                'Um reviewer explícito é obrigatório para review.',
            );
        }

        return DB::transaction(function () use (
            $version,
            $decision,
            $reviewerIdentifier,
            $reason,
        ): PuCandidateCurveReviewResult {
            $lockedVersion = EmissionPuCurveVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->first();
            $lockedInspection = $this->inspect($lockedVersion, $decision, $reason);

            if (! $lockedVersion instanceof EmissionPuCurveVersion
                || $lockedInspection->action !== self::ACTION_READY) {
                return $lockedInspection;
            }

            $makerCheckerId = $this->resolveUserId($reviewerIdentifier);

            if ($makerCheckerId !== null && (int) $lockedVersion->generated_by === $makerCheckerId) {
                return $this->result(
                    $lockedVersion,
                    $decision,
                    self::ACTION_MAKER_CHECKER_VIOLATION,
                    'Maker e checker devem ser usuários distintos.',
                    reviewerId: $makerCheckerId,
                );
            }

            [$reviewer, $reviewerAction, $reviewerReason] = $this->reviewerForWrite($reviewerIdentifier);

            if (! $reviewer instanceof User) {
                return $this->result(
                    $lockedVersion,
                    $decision,
                    $reviewerAction,
                    $reviewerReason,
                );
            }

            $normalizedReason = filled(trim((string) $reason)) ? trim((string) $reason) : null;
            $reviewStatus = $decision === PuCandidateReviewDecision::Approve
                ? PuCurveReviewStatus::Approved
                : PuCurveReviewStatus::Rejected;
            $lockedVersion->forceFill([
                'review_status' => $reviewStatus,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_reason' => $normalizedReason,
            ])->save();
            $this->auditLog->logCandidateCurveReview(
                version: $lockedVersion,
                reviewer: $reviewer,
                decision: $decision,
                reason: $normalizedReason,
            );

            return $this->result(
                $lockedVersion,
                $decision,
                $decision === PuCandidateReviewDecision::Approve
                    ? self::ACTION_APPROVED
                    : self::ACTION_REJECTED,
                $decision === PuCandidateReviewDecision::Approve
                    ? 'Candidate aprovada internamente; permanece candidate e não operacional.'
                    : 'Candidate rejeitada e preservada como artefato histórico.',
                reviewerId: $reviewer->id,
                writes: 1,
            );
        });
    }

    private function resolveUserId(string $identifier): ?int
    {
        $normalizedIdentifier = trim($identifier);

        if ($normalizedIdentifier === '') {
            return null;
        }

        $query = User::query();
        $reviewer = ctype_digit($normalizedIdentifier)
            ? $query->whereKey((int) $normalizedIdentifier)->first(['id'])
            : $query->where('email', $normalizedIdentifier)->first(['id']);

        return $reviewer?->getKey();
    }

    /** @return array{0:?User,1:string,2:string} */
    private function reviewerForWrite(string $identifier): array
    {
        $normalizedIdentifier = trim($identifier);
        $query = User::query()->lockForUpdate();
        $reviewer = ctype_digit($normalizedIdentifier)
            ? $query->whereKey((int) $normalizedIdentifier)->first()
            : $query->where('email', $normalizedIdentifier)->first();

        if (! $reviewer instanceof User) {
            return [null, self::ACTION_REVIEWER_NOT_FOUND, 'O reviewer explícito não existe.'];
        }

        if (! $reviewer->isActive()) {
            return [null, self::ACTION_REVIEWER_INACTIVE, 'O reviewer explícito está inativo.'];
        }

        if (! $reviewer->isApproved()) {
            return [null, self::ACTION_REVIEWER_UNAPPROVED, 'O reviewer explícito ainda não foi aprovado.'];
        }

        if (! $reviewer->can(AccessPermission::PuCurveHomologate->value)) {
            return [null, self::ACTION_REVIEWER_UNAUTHORIZED, sprintf(
                'O reviewer explícito não possui a permission %s.',
                AccessPermission::PuCurveHomologate->value,
            )];
        }

        return [$reviewer, '', ''];
    }

    private function result(
        EmissionPuCurveVersion $version,
        PuCandidateReviewDecision $decision,
        string $action,
        string $reason,
        ?int $reviewerId = null,
        int $writes = 0,
    ): PuCandidateCurveReviewResult {
        return new PuCandidateCurveReviewResult(
            action: $action,
            reason: $reason,
            candidateVersionId: $version->id,
            calculationVersion: $version->calculation_version,
            curveRole: $version->curve_role?->value,
            reviewStatus: $version->review_status?->value,
            internalValidationStatus: $version->internal_validation_status?->value,
            externalValidationStatus: $version->external_validation_status?->value,
            makerId: $version->generated_by,
            reviewerId: $reviewerId ?? $version->reviewed_by,
            decision: $decision->value,
            writes: $writes,
        );
    }
}
