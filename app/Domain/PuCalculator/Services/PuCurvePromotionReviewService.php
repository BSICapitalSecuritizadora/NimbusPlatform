<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionResult;
use App\Domain\PuCalculator\Enums\PuCurvePromotionDecision;
use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalValidation;
use Illuminate\Support\Facades\DB;

/**
 * Decisão independente sobre um pedido de promoção operacional.
 *
 * Aprovar NÃO executa: a troca operacional exige um comando explícito de
 * execução, que revalida a integridade imediatamente antes do switch. Decisão
 * humana e efeito operacional são eventos diferentes, e separá-los reduz a
 * janela de TOCTOU entre a aprovação e a troca.
 */
final class PuCurvePromotionReviewService
{
    public const ACTION_READY = 'ready_for_promotion_review';

    public const ACTION_APPROVED = 'promotion_approved';

    public const ACTION_REJECTED = 'promotion_rejected';

    public const ACTION_ALREADY_APPROVED = 'promotion_already_approved';

    public const ACTION_ALREADY_REJECTED = 'promotion_already_rejected';

    public const ACTION_ALREADY_EXECUTED = 'promotion_already_executed';

    public const ACTION_NOT_FOUND = 'promotion_not_found';

    public const ACTION_NOT_REVIEWABLE = 'promotion_not_reviewable';

    public const ACTION_INTEGRITY_FAILURE = 'promotion_integrity_failure';

    public const ACTION_REASON_REQUIRED = 'promotion_rejection_reason_required';

    public const ACTION_REVIEW_CONFLICT = 'promotion_decision_conflict';

    public const ACTION_REVIEWER_REQUIRED = 'promotion_reviewer_required';

    public const ACTION_REVIEWER_NOT_FOUND = 'promotion_reviewer_not_found';

    public const ACTION_REVIEWER_INACTIVE = 'promotion_reviewer_inactive';

    public const ACTION_REVIEWER_UNAPPROVED = 'promotion_reviewer_unapproved';

    public const ACTION_REVIEWER_UNAUTHORIZED = 'promotion_reviewer_unauthorized';

    public const ACTION_INDEPENDENCE_VIOLATION = 'promotion_independence_violation';

    public function __construct(
        private readonly PuCurvePromotionEligibilityService $eligibility,
        private readonly PuCurvePromotionActorService $actors,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function inspect(
        ?EmissionPuCurvePromotion $promotion,
        PuCurvePromotionDecision $decision,
        ?string $reason = null,
        ?string $reviewerIdentifier = null,
    ): PuCurvePromotionResult {
        if (! $promotion instanceof EmissionPuCurvePromotion) {
            return new PuCurvePromotionResult(
                action: self::ACTION_NOT_FOUND,
                reason: 'The promotion dossier does not exist.',
                decision: $decision->value,
            );
        }

        if ($promotion->status === PuCurvePromotionStatus::Executed) {
            return $this->result(
                $promotion,
                $decision,
                self::ACTION_ALREADY_EXECUTED,
                'This promotion was already executed; its review decision is final.',
            );
        }

        if ($promotion->status === PuCurvePromotionStatus::Approved) {
            return $this->result(
                $promotion,
                $decision,
                $decision === PuCurvePromotionDecision::Approve
                    ? self::ACTION_ALREADY_APPROVED
                    : self::ACTION_REVIEW_CONFLICT,
                $decision === PuCurvePromotionDecision::Approve
                    ? 'This promotion is already approved; no write will be repeated.'
                    : 'A final approval cannot be replaced by a rejection.',
            );
        }

        if ($promotion->status === PuCurvePromotionStatus::Rejected) {
            return $this->result(
                $promotion,
                $decision,
                $decision === PuCurvePromotionDecision::Reject
                    ? self::ACTION_ALREADY_REJECTED
                    : self::ACTION_REVIEW_CONFLICT,
                $decision === PuCurvePromotionDecision::Reject
                    ? 'This promotion is already rejected; no write will be repeated.'
                    : 'A final rejection cannot be replaced by an approval.',
            );
        }

        if ($promotion->status !== PuCurvePromotionStatus::PendingReview) {
            return $this->result(
                $promotion,
                $decision,
                self::ACTION_NOT_REVIEWABLE,
                'The promotion review workflow is not pending.',
            );
        }

        if ($decision === PuCurvePromotionDecision::Reject && trim((string) $reason) === '') {
            return $this->result(
                $promotion,
                $decision,
                self::ACTION_REASON_REQUIRED,
                'Promotion rejection requires a non-empty reason.',
            );
        }

        $candidate = $promotion->relationLoaded('candidate')
            ? $promotion->candidate
            : $promotion->candidate()->first();
        $eligibility = $this->eligibility->inspect($candidate);

        if (! $eligibility['ready'] || ! $candidate instanceof EmissionPuCurveVersion) {
            return $this->result(
                $promotion,
                $decision,
                $eligibility['action'] === PuCurvePromotionEligibilityService::ACTION_INTEGRITY_FAILURE
                    ? self::ACTION_INTEGRITY_FAILURE
                    : self::ACTION_NOT_REVIEWABLE,
                $eligibility['reason'],
            );
        }

        $integrity = $this->capturedIdentityMismatch($promotion, $eligibility);

        if ($integrity !== null) {
            return $this->result($promotion, $decision, self::ACTION_INTEGRITY_FAILURE, $integrity);
        }

        if (trim((string) $reviewerIdentifier) !== '') {
            $resolution = $this->actors->resolve($reviewerIdentifier);

            if (! $resolution->resolved() || $resolution->actor === null) {
                return $this->result(
                    $promotion,
                    $decision,
                    $this->reviewerAction($resolution->failure),
                    $resolution->reason ?? 'An explicit authorized independent promotion reviewer is required.',
                );
            }

            if ($this->violatesIndependence($promotion, $candidate, $resolution->actor->id)) {
                return $this->result(
                    $promotion,
                    $decision,
                    self::ACTION_INDEPENDENCE_VIOLATION,
                    'The promotion reviewer must differ from the requester, the candidate maker, the internal reviewer and the external reviewer.',
                    reviewerId: $resolution->actor->id,
                );
            }

            return $this->result(
                $promotion,
                $decision,
                self::ACTION_READY,
                'The promotion dossier and the independent reviewer are ready for a final decision.',
                reviewerId: $resolution->actor->id,
            );
        }

        return $this->result(
            $promotion,
            $decision,
            self::ACTION_READY,
            'The promotion dossier is intact and pending an independent decision.',
        );
    }

    public function write(
        ?EmissionPuCurvePromotion $promotion,
        PuCurvePromotionDecision $decision,
        ?string $reviewerIdentifier,
        ?string $reason = null,
    ): PuCurvePromotionResult {
        if ($promotion instanceof EmissionPuCurvePromotion) {
            $promotion = $promotion->fresh() ?? $promotion;
        }

        $inspection = $this->inspect($promotion, $decision, $reason);

        if (! $promotion instanceof EmissionPuCurvePromotion
            || $inspection->action !== self::ACTION_READY) {
            return $inspection;
        }

        if (trim((string) $reviewerIdentifier) === '') {
            return $this->result(
                $promotion,
                $decision,
                self::ACTION_REVIEWER_REQUIRED,
                'An explicit authorized independent promotion reviewer is required for writes.',
            );
        }

        return DB::transaction(function () use (
            $promotion,
            $decision,
            $reviewerIdentifier,
            $reason,
        ): PuCurvePromotionResult {
            $lockedPromotion = EmissionPuCurvePromotion::query()
                ->whereKey($promotion->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPromotion instanceof EmissionPuCurvePromotion) {
                return new PuCurvePromotionResult(
                    action: self::ACTION_NOT_FOUND,
                    reason: 'The promotion dossier no longer exists.',
                    decision: $decision->value,
                );
            }

            $lockedCandidate = EmissionPuCurveVersion::query()
                ->whereKey($lockedPromotion->candidate_curve_version_id)
                ->lockForUpdate()
                ->first();
            $lockedPromotion->setRelation('candidate', $lockedCandidate);
            $lockedInspection = $this->inspect($lockedPromotion, $decision, $reason);

            if ($lockedInspection->action !== self::ACTION_READY
                || ! $lockedCandidate instanceof EmissionPuCurveVersion) {
                return $lockedInspection;
            }

            $resolution = $this->actors->resolve($reviewerIdentifier, lockForUpdate: true);

            if (! $resolution->resolved() || $resolution->actor === null) {
                return $this->result(
                    $lockedPromotion,
                    $decision,
                    $this->reviewerAction($resolution->failure),
                    $resolution->reason ?? 'An explicit authorized independent promotion reviewer is required.',
                );
            }

            if ($this->violatesIndependence($lockedPromotion, $lockedCandidate, $resolution->actor->id)) {
                return $this->result(
                    $lockedPromotion,
                    $decision,
                    self::ACTION_INDEPENDENCE_VIOLATION,
                    'The promotion reviewer must differ from the requester, the candidate maker, the internal reviewer and the external reviewer.',
                    reviewerId: $resolution->actor->id,
                );
            }

            $normalizedReason = trim((string) $reason);
            $status = $decision === PuCurvePromotionDecision::Approve
                ? PuCurvePromotionStatus::Approved
                : PuCurvePromotionStatus::Rejected;
            $lockedPromotion->forceFill([
                'status' => $status,
                'reviewed_by' => $resolution->actor->id,
                'reviewed_at' => now(),
                'review_reason' => $normalizedReason !== '' ? $normalizedReason : null,
            ])->save();
            $this->auditLog->logCurvePromotionReviewed(
                promotion: $lockedPromotion,
                reviewer: $resolution->actor,
                decision: $decision,
            );

            return $this->result(
                $lockedPromotion,
                $decision,
                $decision === PuCurvePromotionDecision::Approve
                    ? self::ACTION_APPROVED
                    : self::ACTION_REJECTED,
                $decision === PuCurvePromotionDecision::Approve
                    ? 'The promotion was approved; no operational switch happened and an explicit execution is still required.'
                    : 'The promotion was rejected and preserved as a historical governance artifact.',
                reviewerId: $resolution->actor->id,
                writes: 1,
            );
        });
    }

    /**
     * A identidade capturada no pedido tem de continuar valendo. Uma aprovação
     * sobre um baseline que já mudou seria uma decisão sobre outro artefato.
     *
     * @param  array<string, mixed>  $eligibility
     */
    private function capturedIdentityMismatch(EmissionPuCurvePromotion $promotion, array $eligibility): ?string
    {
        if (! hash_equals($promotion->candidate_checksum, (string) $eligibility['candidateChecksum'])) {
            return 'The candidate checksum captured in the promotion request no longer matches the candidate dossier.';
        }

        if (! hash_equals($promotion->input_fingerprint, (string) $eligibility['inputFingerprint'])) {
            return 'The input fingerprint captured in the promotion request no longer matches the candidate dossier.';
        }

        if ($promotion->rows_count !== $eligibility['rowsCount']) {
            return 'The row count captured in the promotion request no longer matches the candidate dossier.';
        }

        $validation = $eligibility['externalValidation'];

        if (! $validation instanceof EmissionPuExternalValidation
            || $promotion->external_validation_id !== $validation->id) {
            return 'The external validation captured in the promotion request is no longer the validated dossier of this candidate.';
        }

        if (! hash_equals($promotion->benchmark_dataset_sha256, (string) $eligibility['benchmarkChecksum'])) {
            return 'The benchmark dataset checksum captured in the promotion request no longer matches the immutable benchmark.';
        }

        if (! hash_equals($promotion->comparison_sha256, (string) $eligibility['comparisonChecksum'])) {
            return 'The comparison checksum captured in the promotion request no longer matches the persisted comparison.';
        }

        return null;
    }

    /**
     * Independência da promoção: o revisor não pode ser o solicitante nem
     * qualquer um dos três papéis já formalizados sobre a candidate (maker da
     * curva, revisor interno e revisor externo). Aprovar a troca operacional é a
     * quarta decisão da cadeia e precisa de um quarto olhar.
     */
    private function violatesIndependence(
        EmissionPuCurvePromotion $promotion,
        EmissionPuCurveVersion $candidate,
        int $reviewerId,
    ): bool {
        $externalReviewerId = $promotion->externalValidation()->value('reviewed_by');

        return in_array($reviewerId, array_filter([
            (int) $promotion->requested_by,
            (int) $candidate->generated_by,
            (int) $candidate->reviewed_by,
            (int) $externalReviewerId,
        ]), true);
    }

    private function reviewerAction(?string $failure): string
    {
        return match ($failure) {
            PuCurvePromotionActorService::ACTOR_NOT_FOUND => self::ACTION_REVIEWER_NOT_FOUND,
            PuCurvePromotionActorService::ACTOR_INACTIVE => self::ACTION_REVIEWER_INACTIVE,
            PuCurvePromotionActorService::ACTOR_UNAPPROVED => self::ACTION_REVIEWER_UNAPPROVED,
            PuCurvePromotionActorService::ACTOR_UNAUTHORIZED => self::ACTION_REVIEWER_UNAUTHORIZED,
            default => self::ACTION_REVIEWER_REQUIRED,
        };
    }

    private function result(
        EmissionPuCurvePromotion $promotion,
        PuCurvePromotionDecision $decision,
        string $action,
        string $reason,
        ?int $reviewerId = null,
        int $writes = 0,
    ): PuCurvePromotionResult {
        return new PuCurvePromotionResult(
            action: $action,
            reason: $reason,
            promotionId: $promotion->id,
            promotionStatus: $promotion->status?->value,
            candidateVersionId: $promotion->candidate_curve_version_id,
            calculationVersion: $promotion->calculation_version,
            previousOperationalVersionId: $promotion->previous_operational_curve_version_id,
            externalValidationId: $promotion->external_validation_id,
            requesterId: $promotion->requested_by,
            reviewerId: $reviewerId ?? $promotion->reviewed_by,
            decision: $decision->value,
            writes: $writes,
        );
    }
}
