<?php

namespace App\Services\Nimbus;

use App\Models\Nimbus\Submission;
use App\Models\Nimbus\SubmissionStatusHistory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmissionWorkflowService
{
    /**
     * Allowed transitions. Any transition not listed is invalid.
     * null represents creation (no old status).
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'INITIAL' => [Submission::STATUS_PENDING],
        Submission::STATUS_PENDING => [Submission::STATUS_UNDER_REVIEW, Submission::STATUS_REJECTED],
        Submission::STATUS_UNDER_REVIEW => [
            Submission::STATUS_NEEDS_CORRECTION,
            Submission::STATUS_COMPLETED,
            Submission::STATUS_REJECTED,
        ],
        Submission::STATUS_NEEDS_CORRECTION => [Submission::STATUS_UNDER_REVIEW],
        // Terminal states have no outgoing transitions.
        Submission::STATUS_COMPLETED => [],
        Submission::STATUS_REJECTED => [],
    ];

    /**
     * Transition submission to new status, creating immutable history and updating submission.
     * Atomic: history + submission update in same transaction.
     *
     * @throws ValidationException on invalid transition
     */
    public function transition(
        Submission $submission,
        string $newStatus,
        ?Authenticatable $actor = null,
        ?string $reason = null,
    ): Submission {
        $newStatus = Submission::persistableStatusFor($newStatus);
        $oldStatus = $submission->status;

        if ($oldStatus === $newStatus) {
            // No-op for internal_comment case; still record note via caller, but no history.
            // We allow same-status with reason as note-only (no history).
            return $submission;
        }

        if (! $this->isAllowedTransition($oldStatus, $newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Transição inválida de {$oldStatus} para {$newStatus}.",
            ]);
        }

        return DB::transaction(function () use ($submission, $oldStatus, $newStatus, $actor, $reason): Submission {
            SubmissionStatusHistory::create([
                'nimbus_submission_id' => $submission->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'actor_type' => $actor ? $actor::class : null,
                'actor_id' => $actor?->getAuthIdentifier(),
                'reason' => $reason ? mb_substr($reason, 0, 2000) : null,
            ]);

            $submission->update([
                'status' => $newStatus,
                'status_updated_at' => now(),
                'status_updated_by' => $actor instanceof User ? $actor->id : null,
            ]);

            // Audit: explicit activity log for status transition (distinct from history).
            activity('nimbus')
                ->performedOn($submission)
                ->causedBy($actor)
                ->withProperties([
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'reason' => $reason ? mb_substr($reason, 0, 500) : null,
                ])
                ->log('nimbus.submission.status_changed');

            return $submission->refresh();
        });
    }

    /**
     * Record initial creation history (old_status = null).
     */
    public function recordCreation(
        Submission $submission,
        ?Authenticatable $actor = null,
    ): void {
        // Only create if no history exists yet (idempotent for backfill).
        $exists = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)->exists();

        if ($exists) {
            return;
        }

        SubmissionStatusHistory::create([
            'nimbus_submission_id' => $submission->id,
            'old_status' => null,
            'new_status' => $submission->status,
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'reason' => null,
        ]);

        activity('nimbus')
            ->performedOn($submission)
            ->causedBy($actor)
            ->withProperties([
                'new_status' => $submission->status,
            ])
            ->log('nimbus.submission.created');
    }

    public function isAllowedTransition(?string $oldStatus, string $newStatus): bool
    {
        $key = $oldStatus ?? 'INITIAL';

        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$key] ?? [], true);
    }

    /**
     * Derive correction count from history (number of transitions to NEEDS_CORRECTION).
     */
    public static function correctionCount(Submission $submission): int
    {
        return SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)
            ->where('new_status', Submission::STATUS_NEEDS_CORRECTION)
            ->count();
    }

    public static function lastCorrectionRequestedAt(Submission $submission): ?CarbonInterface
    {
        $record = SubmissionStatusHistory::where('nimbus_submission_id', $submission->id)
            ->where('new_status', Submission::STATUS_NEEDS_CORRECTION)
            ->latest('created_at')
            ->first();

        return $record?->created_at;
    }
}
