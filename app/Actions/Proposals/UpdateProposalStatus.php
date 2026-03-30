<?php

namespace App\Actions\Proposals;

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
        string $newStatus,
        ?User $user = null,
        ?string $note = null,
        bool $authorize = true,
    ): ProposalStatusHistory {
        if ($authorize && ! $this->canChangeStatus($proposal, $user)) {
            throw new AuthorizationException('Você não pode alterar o status desta proposta.');
        }

        $currentStatus = $proposal->status;

        if ($currentStatus === $newStatus) {
            throw ValidationException::withMessages([
                'status' => 'Selecione um novo status para continuar.',
            ]);
        }

        if (! in_array($newStatus, Proposal::allowedStatusTransitions($currentStatus), true)) {
            throw ValidationException::withMessages([
                'status' => 'A transição de status informada não é permitida.',
            ]);
        }

        $normalizedNote = filled($note) ? trim((string) $note) : null;

        if (Proposal::requiresStatusNote($newStatus) && blank($normalizedNote)) {
            throw ValidationException::withMessages([
                'note' => 'Informe uma observação para justificar esta mudança de status.',
            ]);
        }

        $history = DB::transaction(function () use ($proposal, $currentStatus, $newStatus, $user, $normalizedNote): ProposalStatusHistory {
            $proposal->forceFill([
                'status' => $newStatus,
            ])->save();

            return $this->recordHistory($proposal, $currentStatus, $newStatus, $user, $normalizedNote);
        });

        $this->notifyProposalStatusChange->handle($proposal->fresh(['company', 'contact', 'latestContinuationAccess']), $newStatus);

        return $history;
    }

    public function recordHistory(
        Proposal $proposal,
        ?string $previousStatus,
        string $newStatus,
        ?User $user = null,
        ?string $note = null,
    ): ProposalStatusHistory {
        return $proposal->statusHistories()->create([
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changed_by_user_id' => $user?->id,
            'note' => blank($note) ? null : trim($note),
            'changed_at' => now(),
        ]);
    }

    protected function canChangeStatus(Proposal $proposal, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        return $user->can('proposals.update') && $proposal->isAssignedToUser($user);
    }
}
