<?php

namespace App\Observers;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\User;
use Filament\Notifications\Notification;

class ProposalObserver
{
    public function created(Proposal $proposal): void
    {
        $this->notifyRepresentative($proposal, 'Nova proposta recebida', 'Uma nova proposta foi atribuída a você.');
    }

    public function updated(Proposal $proposal): void
    {
        if ($proposal->wasChanged('assigned_representative_id')) {
            if ($proposal->assigned_representative_id) {
                $this->notifyRepresentative($proposal, 'Nova atribuição de proposta', 'Uma proposta foi recém-atribuída a você.');
            }
        }

        if ($proposal->wasChanged('status') && ProposalStatus::fromValue($proposal->status) === ProposalStatus::AwaitingInformation) {
            $this->notifyRepresentative($proposal, 'Atenção na Proposta', 'A proposta foi marcada como aguardando informações adicionais.');
        }
    }

    protected function notifyRepresentative(Proposal $proposal, string $title, string $body): void
    {
        $representative = $proposal->representative;

        if ($representative && $representative->user_id) {
            $user = User::query()->find($representative->user_id);
            if ($user) {
                Notification::make()
                    ->title($title)
                    ->body("{$body} Empresa: {$proposal->company?->name}")
                    ->info()
                    ->sendToDatabase($user);
            }
        }
    }
}
