<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProposal extends EditRecord
{
    protected static string $resource = ProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProposalResource::getChangeStatusAction(),
            Action::make('resend_access')
                ->label('Reenviar acesso')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(SendProposalContinuationLink::class)->handle(
                        $this->record->loadMissing(['company', 'contact']),
                    );

                    $this->record->refresh();

                    Notification::make()
                        ->title('Novo link e codigo gerados para esta proposta.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
