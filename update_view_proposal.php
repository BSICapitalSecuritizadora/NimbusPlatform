<?php

$content = file_get_contents('app/Filament/Resources/Proposals/Pages/ViewProposal.php');

$newImports = <<<PHP
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
PHP;

$content = preg_replace('/use App\\\\Actions\\\\Proposals\\\\SendProposalContinuationLink;.*?use Illuminate\\\\Support\\\\Facades\\\\RateLimiter;/s', $newImports, $content);

$newActions = <<<'PHP'
    protected function getHeaderActions(): array
    {
        return [
            Action::make('mark_in_review')
                ->label('Marcar como em análise')
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::InReview->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->action(fn () => $this->changeStatus(ProposalStatus::InReview->value)),

            Action::make('request_info')
                ->label('Solicitar complemento')
                ->icon('heroicon-o-document-plus')
                ->color('warning')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::AwaitingInformation->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
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
                ->action(fn () => $this->changeStatus(ProposalStatus::Approved->value)),

            Action::make('reject')
                ->label('Recusar com justificativa')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::Rejected->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
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
                ->action(fn () => $this->changeStatus(ProposalStatus::Completed->value)),

            Action::make('resend_access')
                ->label('Enviar link seguro')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
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
PHP;

$content = preg_replace('/protected function getHeaderActions\(\): array\s*\{.*?\n    \}/s', $newActions, $content);

file_put_contents('app/Filament/Resources/Proposals/Pages/ViewProposal.php', $content);
echo "ViewProposal updated successfully.\n";
