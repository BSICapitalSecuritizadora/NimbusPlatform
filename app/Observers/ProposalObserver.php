<?php

namespace App\Observers;

use App\Models\Proposal;
use Filament\Notifications\Notification;

class ProposalObserver
{
    public function created(Proposal $proposal): void
    {
        // Se a proposta foi criada (geralmente via site), e já tem um responsável, notificá-lo.
        $this->notifyRepresentative($proposal, 'Nova proposta recebida', 'Uma nova proposta foi atribuída a você.');
    }

    public function updated(Proposal $proposal): void
    {
        if ($proposal->wasChanged('assigned_representative_id')) {
            if ($proposal->assigned_representative_id) {
                $this->notifyRepresentative($proposal, 'Nova atribuição de proposta', 'Uma proposta foi recém-atribuída a você.');
            }
        }

        if ($proposal->wasChanged('status')) {
            // Se status mudou para algo que exija ação
            if ($proposal->status === 'awaiting_complement' || $proposal->status === 'aguardando_complemento') {
                $this->notifyRepresentative($proposal, 'Atenção na Proposta', 'A proposta foi marcada como aguardando complemento.');
            }
        }
    }

    protected function notifyRepresentative(Proposal $proposal, string $title, string $body): void
    {
        $representative = $proposal->representative;

        if ($representative && $representative->user_id) {
            $user = \App\Models\User::find($representative->user_id);
            if ($user) {
                Notification::make()
                    ->title($title)
                    ->body("{$body} Empresa: {$proposal->company?->company_name}")
                    ->info()
                    ->sendToDatabase($user);
            }
        }
    }
}
