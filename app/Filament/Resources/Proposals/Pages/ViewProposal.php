<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\DTOs\Proposals\UpdateProposalStatusDTO;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\RateLimiter;

class ViewProposal extends ViewRecord
{
    protected static string $resource = ProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mark_in_review')
                ->label('Marcar como em análise')
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::InReview->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->modalHeading('Marcar como em análise')
                ->modalDescription('Esta ação mudará o status da proposta para "Em Análise". Deseja continuar?')
                ->modalSubmitActionLabel('Marcar como em análise')
                ->action(fn () => $this->changeStatus(ProposalStatus::InReview->value)),

            Action::make('request_info')
                ->label('Solicitar complemento')
                ->icon('heroicon-o-document-plus')
                ->color('warning')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::AwaitingInformation->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->modalHeading('Solicitar complemento')
                ->modalDescription('O cliente será notificado para enviar informações adicionais. A proposta ficará com status "Aguardando informações".')
                ->modalSubmitActionLabel('Solicitar complemento')
                ->form([
                    Textarea::make('note')
                        ->label('Justificativa')
                        ->required()
                        ->rows(4)
                        ->placeholder('Informe o que está faltando para o cliente complementar.'),
                ])
                ->action(fn (array $data) => $this->changeStatus(ProposalStatus::AwaitingInformation->value, $data['note'])),

            Action::make('approve')
                ->label('Aprovar proposta')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::Approved->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->modalHeading('Aprovar proposta')
                ->modalDescription('A proposta será aprovada e encaminhada para a próxima fase (formalização). Confirma a aprovação?')
                ->modalSubmitActionLabel('Aprovar proposta')
                ->action(fn () => $this->changeStatus(ProposalStatus::Approved->value)),

            Action::make('reject')
                ->label('Recusar com justificativa')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::Rejected->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->modalHeading('Recusar proposta')
                ->modalDescription('A proposta será rejeitada e arquivada. Esta ação não pode ser desfeita facilmente.')
                ->modalSubmitActionLabel('Recusar proposta')
                ->form([
                    Textarea::make('note')
                        ->label('Justificativa')
                        ->required()
                        ->rows(4)
                        ->placeholder('Informe o motivo da recusa da proposta.'),
                ])
                ->action(fn (array $data) => $this->changeStatus(ProposalStatus::Rejected->value, $data['note'])),

            Action::make('complete')
                ->label('Marcar como formalizada')
                ->icon('heroicon-o-flag')
                ->color('success')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::Completed->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->modalHeading('Formalizar proposta')
                ->modalDescription('A proposta será marcada como formalizada/concluída. Confirma?')
                ->modalSubmitActionLabel('Marcar como formalizada')
                ->action(fn () => $this->changeStatus(ProposalStatus::Completed->value)),

            Action::make('resend_access')
                ->label('Enviar link seguro')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Enviar link seguro')
                ->modalDescription('Isto enviará um novo link de acesso seguro por e-mail para o contato principal da proposta. Deseja continuar?')
                ->modalSubmitActionLabel('Enviar link')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record))
                ->action(function (): void {
                    $key = "resend-access:{$this->record->id}";

                    if (RateLimiter::tooManyAttempts($key, 1)) {
                        Notification::make()
                            ->title('Aguarde alguns minutos antes de enviar o link novamente.')
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
                        ->title('Novo link e código de acesso gerados com sucesso.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function changeStatus(string $status, ?string $note = null): void
    {
        app(UpdateProposalStatus::class)->handle(
            $this->record,
            UpdateProposalStatusDTO::fromArray([
                'status' => $status,
                'user' => auth()->user(),
                'note' => $note,
            ]),
        );

        $this->record->refresh();

        Notification::make()
            ->title('Status atualizado com sucesso')
            ->success()
            ->send();
    }
}
