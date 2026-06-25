<?php

namespace App\Services\Obligations;

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Models\ExtractedObligation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ObligationSuggestionReviewService
{
    public const LOG_NAME = 'obligation_suggestions';

    public const EVENT_APPROVED = 'suggestion_approved';

    public const EVENT_REJECTED = 'suggestion_rejected';

    public const TRANSITION_APPROVE = 'approve';

    public const TRANSITION_REJECT = 'reject';

    public function canUserReview(?User $user, string $transition): bool
    {
        if (! $this->canAccessReviewWorkspace($user)) {
            return false;
        }

        return $user?->can($this->permissionForTransition($transition)->value) ?? false;
    }

    public function canRunTransition(?User $user, ExtractedObligation $suggestion, string $transition): bool
    {
        if (! $this->canUserReview($user, $transition)) {
            return false;
        }

        return match ($transition) {
            self::TRANSITION_APPROVE => $this->canApprove($suggestion),
            self::TRANSITION_REJECT => $this->canReject($suggestion),
            default => false,
        };
    }

    public function permissionForTransition(string $transition): AccessPermission
    {
        return match ($transition) {
            self::TRANSITION_APPROVE => AccessPermission::ObligationsApproveSuggestion,
            self::TRANSITION_REJECT => AccessPermission::ObligationsRejectSuggestion,
            default => throw new InvalidArgumentException("Unsupported suggestion review transition [{$transition}]."),
        };
    }

    public function canApprove(ExtractedObligation $suggestion): bool
    {
        if ($suggestion->status !== ExtractedObligation::STATUS_SUGGESTED) {
            return false;
        }

        return ! $suggestion->obligation()->exists();
    }

    public function canReject(ExtractedObligation $suggestion): bool
    {
        return $suggestion->status === ExtractedObligation::STATUS_SUGGESTED;
    }

    public function approve(ExtractedObligation $suggestion, User $actor, ?string $reviewNotes = null): ExtractedObligation
    {
        $this->authorizeTransition($actor, self::TRANSITION_APPROVE);

        if ($suggestion->status !== ExtractedObligation::STATUS_SUGGESTED) {
            $this->throwTransitionException('Esta sugestão não pode ser aprovada no status atual.');
        }

        if ($suggestion->obligation()->exists()) {
            $this->throwTransitionException('Esta sugestão já foi consolidada em uma obrigação.');
        }

        $normalizedNotes = $this->normalizeText($reviewNotes);
        $reviewedAt = now();

        return DB::transaction(function () use ($suggestion, $actor, $normalizedNotes, $reviewedAt): ExtractedObligation {
            if ($suggestion->obligation()->exists()) {
                $this->throwTransitionException('Esta sugestão já foi consolidada em uma obrigação.');
            }

            $obligation = $suggestion->emission->obligations()->create(
                ObligationFormFields::mapSuggestionToObligation($suggestion),
            );

            $suggestion->forceFill([
                'status' => ExtractedObligation::STATUS_APPROVED,
                'review_notes' => $normalizedNotes,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt,
            ])->save();

            $this->recordAudit(
                self::EVENT_APPROVED,
                'Sugestão aprovada',
                $suggestion,
                $actor,
                [
                    'old_status' => ExtractedObligation::STATUS_SUGGESTED,
                    'new_status' => ExtractedObligation::STATUS_APPROVED,
                    'review_notes' => $normalizedNotes,
                    'reviewed_at' => $reviewedAt->toDateTimeString(),
                    'obligation_id' => $obligation->id,
                    'confidence_score' => $suggestion->confidence_score,
                ],
            );

            return $suggestion->refresh();
        });
    }

    public function reject(ExtractedObligation $suggestion, User $actor, ?string $rejectionReason): ExtractedObligation
    {
        $this->authorizeTransition($actor, self::TRANSITION_REJECT);

        if (! $this->canReject($suggestion)) {
            $this->throwTransitionException('Esta sugestão não pode ser rejeitada no status atual.');
        }

        $normalizedReason = $this->normalizeText($rejectionReason);

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'review_notes' => 'Informe o motivo da rejeição.',
            ]);
        }

        $reviewedAt = now();

        return DB::transaction(function () use ($suggestion, $actor, $normalizedReason, $reviewedAt): ExtractedObligation {
            $suggestion->forceFill([
                'status' => ExtractedObligation::STATUS_REJECTED,
                'review_notes' => $normalizedReason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt,
            ])->save();

            $this->recordAudit(
                self::EVENT_REJECTED,
                'Sugestão rejeitada',
                $suggestion,
                $actor,
                [
                    'old_status' => ExtractedObligation::STATUS_SUGGESTED,
                    'new_status' => ExtractedObligation::STATUS_REJECTED,
                    'review_notes' => $normalizedReason,
                    'reviewed_at' => $reviewedAt->toDateTimeString(),
                    'confidence_score' => $suggestion->confidence_score,
                ],
            );

            return $suggestion->refresh();
        });
    }

    protected function authorizeTransition(User $actor, string $transition): void
    {
        if ($this->canUserReview($actor, $transition)) {
            return;
        }

        throw new AuthorizationException(match ($transition) {
            self::TRANSITION_APPROVE => 'Você não tem permissão para aprovar esta sugestão.',
            self::TRANSITION_REJECT => 'Você não tem permissão para rejeitar esta sugestão.',
            default => 'Você não tem permissão para revisar esta sugestão.',
        });
    }

    protected function canAccessReviewWorkspace(?User $user): bool
    {
        return $user?->can(AccessPermission::ObligationsReviewSuggestions->value) ?? false;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function recordAudit(
        string $event,
        string $description,
        ExtractedObligation $suggestion,
        User $actor,
        array $properties,
    ): void {
        activity(self::LOG_NAME)
            ->causedBy($actor)
            ->performedOn($suggestion)
            ->event($event)
            ->withProperties(array_merge([
                'emission_id' => $suggestion->emission_id,
                'suggestion_id' => $suggestion->id,
                'title' => $suggestion->title,
            ], $properties))
            ->log($description);
    }

    protected function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function throwTransitionException(string $message): never
    {
        throw ValidationException::withMessages([
            'suggestion_review' => $message,
        ]);
    }
}
