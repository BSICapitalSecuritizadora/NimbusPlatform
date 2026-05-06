<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\RateLimiter;

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
                ->visible(fn (): bool => ProposalResource::canEdit($this->record))
                ->action(function (): void {
                    $key = "resend-access:{$this->record->id}";

                    if (RateLimiter::tooManyAttempts($key, 1)) {
                        Notification::make()
                            ->title('Aguarde alguns minutos antes de reenviar o acesso.')
                            ->danger()
                            ->send();

                        return;
                    }

                    RateLimiter::hit($key, 300);

                    app(SendProposalContinuationLink::class)->handle(
                        $this->record->loadMissing(['company', 'contact']),
                    );

                    $this->record->refresh();

                    Notification::make()
                        ->title('Novo link e código gerados para esta proposta.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
