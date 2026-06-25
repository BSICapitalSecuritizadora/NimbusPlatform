<?php

namespace App\Services\Obligations;

use App\Enums\AccessPermission;
use App\Models\Obligation;
use App\Models\ObligationComment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObligationCommentService
{
    public const BODY_MAX_LENGTH = 2000;

    public function __construct(
        protected ObligationHistoryRecorder $historyRecorder,
    ) {}

    public function canUserView(?User $user): bool
    {
        return ($user?->can(AccessPermission::EmissionsView->value) ?? false)
            && ($user?->can(AccessPermission::ObligationsView->value) ?? false)
            && ($user?->can(AccessPermission::ObligationsViewComments->value) ?? false);
    }

    public function canUserCreate(?User $user): bool
    {
        return $this->canUserView($user)
            && ($user?->can(AccessPermission::ObligationsCreateComment->value) ?? false);
    }

    public function canUserUpdate(?User $user, ObligationComment $comment): bool
    {
        return $this->canUserView($user)
            && ($user?->can(AccessPermission::ObligationsUpdateComment->value) ?? false);
    }

    public function canUserDelete(?User $user, ObligationComment $comment): bool
    {
        return $this->canUserView($user)
            && ($user?->can(AccessPermission::ObligationsDeleteComment->value) ?? false);
    }

    public function create(Obligation $obligation, User $actor, ?string $body): ObligationComment
    {
        $this->authorizeCreate($actor);

        $normalizedBody = $this->normalizeBody($body);

        if ($normalizedBody === null) {
            throw ValidationException::withMessages([
                'body' => 'Informe o conteúdo do comentário.',
            ]);
        }

        return DB::transaction(function () use ($obligation, $actor, $normalizedBody): ObligationComment {
            $comment = $obligation->comments()->create([
                'emission_id' => $obligation->emission_id,
                'user_id' => $actor->id,
                'body' => $normalizedBody,
                'is_internal' => true,
            ]);

            $this->historyRecorder->recordCommentAdded($obligation, $comment, $actor->id);

            return $comment->refresh();
        });
    }

    public function update(ObligationComment $comment, User $actor, ?string $body): ObligationComment
    {
        $this->authorizeUpdate($actor, $comment);

        $normalizedBody = $this->normalizeBody($body);

        if ($normalizedBody === null) {
            throw ValidationException::withMessages([
                'body' => 'Informe o conteúdo do comentário.',
            ]);
        }

        return DB::transaction(function () use ($comment, $actor, $normalizedBody): ObligationComment {
            $comment->forceFill([
                'body' => $normalizedBody,
                'edited_at' => now(),
                'edited_by' => $actor->id,
            ])->save();

            $this->historyRecorder->recordCommentUpdated($comment->obligation, $comment, $actor->id);

            return $comment->refresh();
        });
    }

    public function delete(ObligationComment $comment, User $actor): void
    {
        $this->authorizeDelete($actor, $comment);

        DB::transaction(function () use ($comment, $actor): void {
            $obligation = $comment->obligation;
            $commentId = $comment->id;

            $comment->delete();

            $this->historyRecorder->recordCommentRemoved($obligation, $commentId, $actor->id);
        });
    }

    protected function authorizeCreate(User $actor): void
    {
        if ($this->canUserCreate($actor)) {
            return;
        }

        throw new AuthorizationException('Você não tem permissão para comentar nesta obrigação.');
    }

    protected function authorizeUpdate(User $actor, ObligationComment $comment): void
    {
        if ($this->canUserUpdate($actor, $comment)) {
            return;
        }

        throw new AuthorizationException('Você não tem permissão para editar este comentário.');
    }

    protected function authorizeDelete(User $actor, ObligationComment $comment): void
    {
        if ($this->canUserDelete($actor, $comment)) {
            return;
        }

        throw new AuthorizationException('Você não tem permissão para remover este comentário.');
    }

    protected function normalizeBody(?string $body): ?string
    {
        $normalized = trim((string) $body);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > self::BODY_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'body' => 'O comentário deve ter no máximo '.self::BODY_MAX_LENGTH.' caracteres.',
            ]);
        }

        return $normalized;
    }
}
