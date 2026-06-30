<?php

namespace App\Services\Obligations;

use App\Enums\AccessPermission;
use App\Models\Obligation;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ObligationWorkflowService
{
    public const TRANSITION_SUBMIT_FOR_REVIEW = 'submit_for_review';

    public const TRANSITION_COMPLETE = 'complete';

    public const TRANSITION_MARK_NOT_APPLICABLE = 'mark_not_applicable';

    public const TRANSITION_REOPEN = 'reopen';

    /**
     * Existing active statuses that can still move through the operational
     * workflow. The project currently uses "em_dia" as the default active
     * state instead of a separate "pending" status.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['em_dia', 'a_vencer', 'vencida'];

    /**
     * @var list<string>
     */
    public const COMPLETABLE_STATUSES = ['em_dia', 'a_vencer', 'vencida', 'em_analise'];

    /**
     * @var list<string>
     */
    public const REOPENABLE_STATUSES = ['concluida', 'nao_aplicavel'];

    public function __construct(
        protected ObligationHistoryRecorder $historyRecorder,
    ) {}

    public function canUserRunWorkflow(?User $user, string $transition): bool
    {
        return $user?->can($this->permissionForTransition($transition)->value) ?? false;
    }

    public function canRunTransition(?User $user, Obligation $obligation, string $transition): bool
    {
        if (! $this->canUserRunWorkflow($user, $transition)) {
            return false;
        }

        return match ($transition) {
            self::TRANSITION_SUBMIT_FOR_REVIEW => $this->canSubmitForReview($obligation),
            self::TRANSITION_COMPLETE => $this->canComplete($obligation),
            self::TRANSITION_MARK_NOT_APPLICABLE => $this->canMarkNotApplicable($obligation),
            self::TRANSITION_REOPEN => $this->canReopen($obligation),
            default => false,
        };
    }

    public function permissionForTransition(string $transition): AccessPermission
    {
        return match ($transition) {
            self::TRANSITION_SUBMIT_FOR_REVIEW => AccessPermission::ObligationsSubmitForReview,
            self::TRANSITION_COMPLETE => AccessPermission::ObligationsComplete,
            self::TRANSITION_MARK_NOT_APPLICABLE => AccessPermission::ObligationsMarkNotApplicable,
            self::TRANSITION_REOPEN => AccessPermission::ObligationsReopen,
            default => throw new InvalidArgumentException("Unsupported workflow transition [{$transition}]."),
        };
    }

    public function canSubmitForReview(Obligation $obligation): bool
    {
        return in_array($obligation->status, self::ACTIVE_STATUSES, true);
    }

    public function canComplete(Obligation $obligation): bool
    {
        return in_array($obligation->status, self::COMPLETABLE_STATUSES, true);
    }

    public function canMarkNotApplicable(Obligation $obligation): bool
    {
        return in_array($obligation->status, self::COMPLETABLE_STATUSES, true);
    }

    public function canReopen(Obligation $obligation): bool
    {
        return in_array($obligation->status, self::REOPENABLE_STATUSES, true);
    }

    public function submitForReview(Obligation $obligation, User $actor, ?string $note): Obligation
    {
        $this->authorizeTransition($actor, self::TRANSITION_SUBMIT_FOR_REVIEW);

        $normalizedNote = $this->normalizeText($note);

        if (! $this->canSubmitForReview($obligation)) {
            $this->throwTransitionException('Esta obrigação não pode ser enviada para análise no status atual.');
        }

        if ($normalizedNote === null) {
            throw ValidationException::withMessages([
                'note' => 'Informe uma observação para contextualizar o envio da obrigação para análise.',
            ]);
        }

        $occurredAt = now();
        $oldStatus = $obligation->status;

        return $this->persistTransition(
            $obligation,
            [
                'status' => 'em_analise',
                'submitted_for_review_at' => $occurredAt,
                'submitted_for_review_by' => $actor->id,
                'review_submission_notes' => $normalizedNote,
            ],
            ObligationHistoryEntry::EVENT_SUBMITTED_FOR_REVIEW,
            'Obrigação enviada para análise',
            filled($normalizedNote)
                ? 'Obrigação enviada para análise. Observação: '.$normalizedNote
                : 'Obrigação enviada para análise.',
            [
                'status' => $oldStatus,
            ],
            [
                'status' => 'em_analise',
                'submitted_for_review_at' => $this->formatDateTime($occurredAt),
                'submitted_for_review_by' => $actor->id,
                'review_submission_notes' => $normalizedNote,
            ],
            [
                'note' => $normalizedNote,
                'transition' => self::TRANSITION_SUBMIT_FOR_REVIEW,
            ],
            $actor,
        );
    }

    public function complete(
        Obligation $obligation,
        User $actor,
        ?string $completionNotes,
        bool $confirmWithoutEvidence = false,
    ): Obligation {
        $this->authorizeTransition($actor, self::TRANSITION_COMPLETE);

        $normalizedNotes = $this->normalizeText($completionNotes);

        if (! $this->canComplete($obligation)) {
            $this->throwTransitionException('Esta obrigação não pode ser concluída no status atual.');
        }

        if ($normalizedNotes === null) {
            throw ValidationException::withMessages([
                'completion_notes' => 'Informe uma justificativa de conclusão para registrar como a obrigação foi cumprida.',
            ]);
        }

        $approvedEvidenceCount = $obligation->evidences()->approved()->count();
        $totalEvidenceCount = $obligation->evidences()->count();
        $hasApprovedEvidence = $approvedEvidenceCount > 0;

        if (! $hasApprovedEvidence && ! $confirmWithoutEvidence) {
            throw ValidationException::withMessages([
                'confirm_without_evidence' => 'Para concluir sem evidência aprovada, confirme explicitamente a exceção e mantenha a justificativa registrada.',
            ]);
        }

        $occurredAt = now();
        $oldStatus = $obligation->status;
        $description = $hasApprovedEvidence
            ? 'Obrigação concluída. Justificativa: '.$normalizedNotes
            : 'Obrigação concluída sem evidência aprovada. Justificativa: '.$normalizedNotes;

        return $this->persistTransition(
            $obligation,
            [
                'status' => 'concluida',
                'completed_at' => $occurredAt,
                'completed_by' => $actor->id,
                'completion_notes' => $normalizedNotes,
                'not_applicable_at' => null,
                'not_applicable_by' => null,
                'not_applicable_reason' => null,
            ],
            ObligationHistoryEntry::EVENT_COMPLETED,
            'Obrigação concluída',
            $description,
            [
                'status' => $oldStatus,
            ],
            [
                'status' => 'concluida',
                'completed_at' => $this->formatDateTime($occurredAt),
                'completed_by' => $actor->id,
                'completion_notes' => $normalizedNotes,
            ],
            [
                'completion_notes' => $normalizedNotes,
                'approved_evidence_count' => $approvedEvidenceCount,
                'total_evidence_count' => $totalEvidenceCount,
                'completed_without_approved_evidence' => ! $hasApprovedEvidence,
                'transition' => self::TRANSITION_COMPLETE,
            ],
            $actor,
        );
    }

    public function markNotApplicable(Obligation $obligation, User $actor, ?string $reason): Obligation
    {
        $this->authorizeTransition($actor, self::TRANSITION_MARK_NOT_APPLICABLE);

        $normalizedReason = $this->normalizeText($reason);

        if (! $this->canMarkNotApplicable($obligation)) {
            $this->throwTransitionException('Esta obrigação não pode ser marcada como não aplicável no status atual.');
        }

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Informe o motivo para registrar a obrigação como não aplicável.',
            ]);
        }

        $occurredAt = now();
        $oldStatus = $obligation->status;

        return $this->persistTransition(
            $obligation,
            [
                'status' => 'nao_aplicavel',
                'not_applicable_at' => $occurredAt,
                'not_applicable_by' => $actor->id,
                'not_applicable_reason' => $normalizedReason,
                'completed_at' => null,
                'completed_by' => null,
                'completion_notes' => null,
            ],
            ObligationHistoryEntry::EVENT_MARKED_NOT_APPLICABLE,
            'Obrigação marcada como não aplicável',
            'Obrigação marcada como não aplicável. Motivo: '.$normalizedReason,
            [
                'status' => $oldStatus,
            ],
            [
                'status' => 'nao_aplicavel',
                'not_applicable_at' => $this->formatDateTime($occurredAt),
                'not_applicable_by' => $actor->id,
                'not_applicable_reason' => $normalizedReason,
            ],
            [
                'reason' => $normalizedReason,
                'transition' => self::TRANSITION_MARK_NOT_APPLICABLE,
            ],
            $actor,
        );
    }

    public function reopen(Obligation $obligation, User $actor, ?string $reason): Obligation
    {
        $this->authorizeTransition($actor, self::TRANSITION_REOPEN);

        $normalizedReason = $this->normalizeText($reason);

        if (! $this->canReopen($obligation)) {
            $this->throwTransitionException('Esta obrigação não pode ser reaberta no status atual.');
        }

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Informe o motivo da reabertura para retomar o acompanhamento operacional.',
            ]);
        }

        $occurredAt = now();
        $oldStatus = $obligation->status;
        $reopenedStatus = $this->resolveReopenedStatus($obligation);

        return $this->persistTransition(
            $obligation,
            [
                'status' => $reopenedStatus,
                'reopened_at' => $occurredAt,
                'reopened_by' => $actor->id,
                'reopen_reason' => $normalizedReason,
                'completed_at' => null,
                'completed_by' => null,
                'completion_notes' => null,
                'not_applicable_at' => null,
                'not_applicable_by' => null,
                'not_applicable_reason' => null,
            ],
            ObligationHistoryEntry::EVENT_REOPENED,
            'Obrigação reaberta',
            'Obrigação reaberta. Motivo: '.$normalizedReason,
            [
                'status' => $oldStatus,
            ],
            [
                'status' => $reopenedStatus,
                'reopened_at' => $this->formatDateTime($occurredAt),
                'reopened_by' => $actor->id,
                'reopen_reason' => $normalizedReason,
            ],
            [
                'reason' => $normalizedReason,
                'transition' => self::TRANSITION_REOPEN,
                'reopened_to_status' => $reopenedStatus,
            ],
            $actor,
        );
    }

    public function resolveReopenedStatus(Obligation $obligation): string
    {
        if ($obligation->due_date === null) {
            return 'em_dia';
        }

        return $obligation->due_date->copy()->startOfDay()->lt(now()->startOfDay())
            ? 'vencida'
            : 'a_vencer';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    protected function persistTransition(
        Obligation $obligation,
        array $attributes,
        string $eventType,
        string $title,
        string $description,
        array $oldValues,
        array $newValues,
        array $metadata,
        User $actor,
    ): Obligation {
        return DB::transaction(function () use (
            $obligation,
            $attributes,
            $eventType,
            $title,
            $description,
            $oldValues,
            $newValues,
            $metadata,
            $actor,
        ): Obligation {
            $obligation->forceFill($attributes);
            $obligation->saveQuietly();

            $this->historyRecorder->recordWorkflowTransition(
                $obligation,
                $eventType,
                $title,
                $description,
                $oldValues,
                $newValues,
                $metadata,
                $actor->id,
            );

            return $obligation->refresh();
        });
    }

    protected function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function throwTransitionException(string $message): never
    {
        throw ValidationException::withMessages([
            'workflow' => $message,
        ]);
    }

    protected function authorizeTransition(User $actor, string $transition): void
    {
        if ($this->canUserRunWorkflow($actor, $transition)) {
            return;
        }

        throw new AuthorizationException(
            'Você não tem permissão para executar esta ação operacional da obrigação.'
        );
    }

    protected function formatDateTime(CarbonInterface $value): string
    {
        return $value->toDateTimeString();
    }
}
