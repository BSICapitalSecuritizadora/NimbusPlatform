<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\DTOs\Proposals\UpdateProposalStatusDTO;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

class ViewProposal extends ViewRecord
{
    protected static string $resource = ProposalResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-proposal-view-page',
    ];

    public function getTitle(): string|Htmlable
    {
        return $this->record->company?->name ?? "Proposta #{$this->record->id}";
    }

    public function getSubheading(): string|Htmlable|null
    {
        $id = $this->record->id;
        $statusLabel = ProposalStatus::labelFor($this->record->status);
        $repName = $this->record->representative?->name ?? 'Não atribuído';
        $created = $this->record->created_at ? $this->record->created_at->format('d/m/Y') : '—';
        $updatedAt = $this->record->updated_at;
        $timeInStatus = $updatedAt
            ? ($updatedAt->diffInSeconds(now()) < 60 ? 'agora' : $updatedAt->diffForHumans())
            : '—';

        return new HtmlString(
            '<span class="bsi-proposal-meta-row bsi-proposal-meta-row--primary">'
            .'<strong class="bsi-proposal-meta-id">Proposta #'.e($id).'</strong>'
            .'<span class="bsi-proposal-status">'.e($statusLabel).'</span>'
            .'</span>'
            .'<span class="bsi-proposal-meta-row">'
            .'<span class="bsi-proposal-meta-label">Responsável:</span> '.e($repName)
            .'</span>'
            .'<span class="bsi-proposal-meta-row">'
            .'<span class="bsi-proposal-meta-label">Entrada:</span> '.e($created)
            .'<span class="bsi-proposal-meta-sep" aria-hidden="true">•</span>'
            .'<span class="bsi-proposal-meta-label">Atualizado:</span> '.e($timeInStatus)
            .'</span>'
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            // ── Primary Contextual CTA ──
            Action::make('mark_in_review')
                ->label('Iniciar Análise')
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::InReview->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->modalHeading('Marcar como em análise')
                ->modalDescription('Esta ação mudará o status da proposta para "Em Análise". Deseja continuar?')
                ->modalSubmitActionLabel('Marcar como em análise')
                ->action(fn () => $this->changeStatus(ProposalStatus::InReview->value)),

            Action::make('approve')
                ->label('Aprovar Proposta')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::Approved->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->modalHeading('Aprovar proposta')
                ->modalDescription('A proposta será aprovada e encaminhada para a próxima fase (formalização). Confirma a aprovação?')
                ->modalSubmitActionLabel('Aprovar proposta')
                ->action(fn () => $this->changeStatus(ProposalStatus::Approved->value)),

            Action::make('complete')
                ->label('Formalizar Proposta')
                ->icon('heroicon-o-flag')
                ->color('success')
                ->visible(fn (): bool => ProposalResource::canEdit($this->record) && array_key_exists(ProposalStatus::Completed->value, app(UpdateProposalStatus::class)->availableStatusOptions($this->record->status)))
                ->requiresConfirmation()
                ->modalHeading('Formalizar proposta')
                ->modalDescription('A proposta será marcada como formalizada/concluída. Confirma?')
                ->modalSubmitActionLabel('Marcar como formalizada')
                ->action(fn () => $this->changeStatus(ProposalStatus::Completed->value)),

            // ── Secondary Operational Actions ──
            Action::make('request_info')
                ->label('Solicitar Complemento')
                ->icon('heroicon-o-document-plus')
                ->color('gray')
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

            // ── Administrative & Tool Actions Group ──
            ActionGroup::make([
                Action::make('proposal_report')
                    ->label('Relatório Geral em PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (): bool => ProposalResource::canView($this->record))
                    ->url(fn (): string => route('admin.proposals.report', $this->record))
                    ->openUrlInNewTab(),

                Action::make('resend_access')
                    ->label('Reenviar Link Seguro')
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
                            ->title('Novo link e código gerados; envio de e-mail enfileirado.')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Recusar Proposta')
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
            ])
                ->label('Mais ações')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray'),
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
