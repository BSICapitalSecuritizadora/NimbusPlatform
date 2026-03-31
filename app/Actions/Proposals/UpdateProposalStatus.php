<?php

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProposalStatus
{
    public function __construct(
        protected NotifyProposalStatusChange $notifyProposalStatusChange,
    ) {}

    public function handle(
        Proposal $proposal,
        ProposalStatus|string $newStatus,
        ?User $user = null,
        ?string $note = null,
        bool $authorize = true,
    ): ProposalStatusHistory {
        $newStatus = $this->normalizeStatus($newStatus);

        if (! $newStatus) {
            throw ValidationException::withMessages([
                'status' => 'Selecione um status válido para continuar.',
            ]);
        }

        if ($authorize && ! $this->canChangeStatus($proposal, $user)) {
            throw new AuthorizationException('Você não pode alterar o status desta proposta.');
        }

        $currentStatus = $this->normalizeStatus($proposal->status);

        if ($currentStatus?->value === $newStatus->value) {
            throw ValidationException::withMessages([
                'status' => 'Selecione um novo status para continuar.',
            ]);
        }

        if (! in_array($newStatus, $this->allowedStatusTransitions($currentStatus), true)) {
            throw ValidationException::withMessages([
                'status' => 'A transição de status informada não é permitida.',
            ]);
        }

        $normalizedNote = filled($note) ? trim((string) $note) : null;

        if ($this->requiresStatusNote($newStatus) && blank($normalizedNote)) {
            throw ValidationException::withMessages([
                'note' => 'Informe uma observação para justificar esta mudança de status.',
            ]);
        }

        $history = DB::transaction(function () use ($proposal, $currentStatus, $newStatus, $user, $normalizedNote): ProposalStatusHistory {
            $proposal->forceFill([
                'status' => $newStatus->value,
            ])->save();

            return $this->recordHistory($proposal, $currentStatus, $newStatus, $user, $normalizedNote);
        });

        $this->notifyProposalStatusChange->handle(
            $proposal->fresh(['company', 'contact', 'latestContinuationAccess']),
            $newStatus,
        );

        return $history;
    }

    /**
     * @return array<string, string>
     */
    public function availableStatusOptions(ProposalStatus|string|null $currentStatus): array
    {
        return collect($this->allowedStatusTransitions($this->normalizeStatus($currentStatus)))
            ->mapWithKeys(fn (ProposalStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    public function canChangeStatus(Proposal $proposal, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        return $user->can('proposals.update') && $proposal->isAssignedToUser($user);
    }

    public function requiresStatusNote(ProposalStatus|string $status): bool
    {
        return in_array($this->normalizeStatus($status), [
            ProposalStatus::AwaitingInformation,
            ProposalStatus::Rejected,
        ], true);
    }

    public function recordHistory(
        Proposal $proposal,
        ProposalStatus|string|null $previousStatus,
        ProposalStatus|string $newStatus,
        ?User $user = null,
        ?string $note = null,
    ): ProposalStatusHistory {
        $previousStatus = $this->normalizeStatus($previousStatus);
        $newStatus = $this->normalizeStatus($newStatus);

        if (! $newStatus) {
            throw ValidationException::withMessages([
                'status' => 'Selecione um status válido para continuar.',
            ]);
        }

        return $proposal->statusHistories()->create([
            'previous_status' => $previousStatus?->value,
            'new_status' => $newStatus->value,
            'changed_by_user_id' => $user?->id,
            'note' => blank($note) ? null : trim($note),
            'changed_at' => now(),
        ]);
    }

    /**
     * @return array<int, ProposalStatus>
     */
    protected function allowedStatusTransitions(?ProposalStatus $currentStatus): array
    {
        return match ($currentStatus) {
            ProposalStatus::AwaitingCompletion => [
                ProposalStatus::InReview,
                ProposalStatus::Rejected,
            ],
            ProposalStatus::InReview => [
                ProposalStatus::AwaitingInformation,
                ProposalStatus::Approved,
                ProposalStatus::Rejected,
            ],
            ProposalStatus::AwaitingInformation => [
                ProposalStatus::InReview,
                ProposalStatus::Rejected,
            ],
            ProposalStatus::Approved => [
                ProposalStatus::InReview,
                ProposalStatus::Completed,
            ],
            ProposalStatus::Rejected => [
                ProposalStatus::InReview,
            ],
            default => [],
        };
    }

    protected function normalizeStatus(ProposalStatus|string|null $status): ?ProposalStatus
    {
        return ProposalStatus::fromValue($status);
    }
}
