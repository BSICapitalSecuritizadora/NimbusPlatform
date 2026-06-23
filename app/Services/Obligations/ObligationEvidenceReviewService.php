<?php

namespace App\Services\Obligations;

use App\Enums\AccessPermission;
use App\Models\ObligationEvidence;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ObligationEvidenceReviewService
{
    public const TRANSITION_APPROVE = 'approve';

    public const TRANSITION_REJECT = 'reject';

    public function __construct(
        protected ObligationHistoryRecorder $historyRecorder,
    ) {}

    public function canUserReview(?User $user, string $transition): bool
    {
        return $user?->can($this->permissionForTransition($transition)->value) ?? false;
    }

    public function canRunTransition(?User $user, ObligationEvidence $evidence, string $transition): bool
    {
        if (! $this->canUserReview($user, $transition)) {
            return false;
        }

        return match ($transition) {
            self::TRANSITION_APPROVE => $this->canApprove($evidence),
            self::TRANSITION_REJECT => $this->canReject($evidence),
            default => false,
        };
    }

    public function canApprove(ObligationEvidence $evidence): bool
    {
        return $evidence->status !== ObligationEvidence::STATUS_APPROVED;
    }

    public function canReject(ObligationEvidence $evidence): bool
    {
        return $evidence->status !== ObligationEvidence::STATUS_REJECTED;
    }

    public function permissionForTransition(string $transition): AccessPermission
    {
        return match ($transition) {
            self::TRANSITION_APPROVE => AccessPermission::ObligationsApproveEvidence,
            self::TRANSITION_REJECT => AccessPermission::ObligationsRejectEvidence,
            default => throw new InvalidArgumentException("Unsupported evidence review transition [{$transition}]."),
        };
    }

    public function approve(ObligationEvidence $evidence, User $actor, ?string $reviewNotes = null): ObligationEvidence
    {
        $this->authorizeTransition($actor, self::TRANSITION_APPROVE);

        if (! $this->canApprove($evidence)) {
            $this->throwTransitionException('Esta evidência não pode ser revisada no status atual.');
        }

        $normalizedNotes = $this->normalizeText($reviewNotes);
        $oldStatus = $evidence->status;
        $reviewedAt = now();
        $description = $oldStatus === ObligationEvidence::STATUS_PENDING
            ? 'Evidência aprovada.'
            : 'Revisão da evidência atualizada.';

        if ($normalizedNotes !== null) {
            $description .= ' Observação: '.$normalizedNotes;
        }

        return DB::transaction(function () use ($evidence, $actor, $normalizedNotes, $oldStatus, $reviewedAt, $description): ObligationEvidence {
            $evidence->forceFill([
                'status' => ObligationEvidence::STATUS_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt,
                'review_notes' => $normalizedNotes,
                'rejection_reason' => null,
            ])->save();

            $this->historyRecorder->recordEvidenceReview(
                $evidence->obligation,
                $oldStatus === ObligationEvidence::STATUS_PENDING
                    ? ObligationHistoryEntry::EVENT_EVIDENCE_APPROVED
                    : ObligationHistoryEntry::EVENT_EVIDENCE_REVIEW_UPDATED,
                $oldStatus === ObligationEvidence::STATUS_PENDING
                    ? 'Evidência aprovada'
                    : 'Revisão da evidência atualizada',
                $description,
                [
                    'status' => $oldStatus,
                ],
                [
                    'status' => ObligationEvidence::STATUS_APPROVED,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $reviewedAt->toDateTimeString(),
                    'review_notes' => $normalizedNotes,
                ],
                [
                    'evidence_id' => $evidence->id,
                    'original_name' => $evidence->original_name,
                    'old_status' => $oldStatus,
                    'new_status' => ObligationEvidence::STATUS_APPROVED,
                    'review_notes' => $normalizedNotes,
                ],
                $actor->id,
            );

            return $evidence->refresh();
        });
    }

    public function reject(ObligationEvidence $evidence, User $actor, ?string $rejectionReason): ObligationEvidence
    {
        $this->authorizeTransition($actor, self::TRANSITION_REJECT);

        if (! $this->canReject($evidence)) {
            $this->throwTransitionException('Esta evidência não pode ser revisada no status atual.');
        }

        $normalizedReason = $this->normalizeText($rejectionReason);

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Informe o motivo da rejeição.',
            ]);
        }

        $oldStatus = $evidence->status;
        $reviewedAt = now();
        $description = $oldStatus === ObligationEvidence::STATUS_PENDING
            ? 'Evidência rejeitada.'
            : 'Revisão da evidência atualizada.';

        $description .= ' Motivo: '.$normalizedReason;

        return DB::transaction(function () use ($evidence, $actor, $normalizedReason, $oldStatus, $reviewedAt, $description): ObligationEvidence {
            $evidence->forceFill([
                'status' => ObligationEvidence::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt,
                'review_notes' => null,
                'rejection_reason' => $normalizedReason,
            ])->save();

            $this->historyRecorder->recordEvidenceReview(
                $evidence->obligation,
                $oldStatus === ObligationEvidence::STATUS_PENDING
                    ? ObligationHistoryEntry::EVENT_EVIDENCE_REJECTED
                    : ObligationHistoryEntry::EVENT_EVIDENCE_REVIEW_UPDATED,
                $oldStatus === ObligationEvidence::STATUS_PENDING
                    ? 'Evidência rejeitada'
                    : 'Revisão da evidência atualizada',
                $description,
                [
                    'status' => $oldStatus,
                ],
                [
                    'status' => ObligationEvidence::STATUS_REJECTED,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $reviewedAt->toDateTimeString(),
                    'rejection_reason' => $normalizedReason,
                ],
                [
                    'evidence_id' => $evidence->id,
                    'original_name' => $evidence->original_name,
                    'old_status' => $oldStatus,
                    'new_status' => ObligationEvidence::STATUS_REJECTED,
                    'rejection_reason' => $normalizedReason,
                ],
                $actor->id,
            );

            return $evidence->refresh();
        });
    }

    protected function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function authorizeTransition(User $actor, string $transition): void
    {
        if ($this->canUserReview($actor, $transition)) {
            return;
        }

        throw new AuthorizationException(match ($transition) {
            self::TRANSITION_APPROVE => 'Você não tem permissão para aprovar esta evidência.',
            self::TRANSITION_REJECT => 'Você não tem permissão para rejeitar esta evidência.',
            default => 'Você não tem permissão para revisar esta evidência.',
        });
    }

    protected function throwTransitionException(string $message): never
    {
        throw ValidationException::withMessages([
            'evidence_review' => $message,
        ]);
    }
}
