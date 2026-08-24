<?php

namespace App\Filament\Resources\Recruitment\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Recruitment\RelationManagers\VacancyApplicationsRelationManager;
use App\Filament\Resources\Recruitment\VacancyResource;
use App\Models\Vacancy;
use App\Services\Recruitment\VacancySlugService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewVacancy extends ViewRecord
{
    protected static string $resource = VacancyResource::class;

    protected static ?string $title = 'Visualizar Vaga';

    protected static ?string $breadcrumb = 'Visualizar';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('duplicate')
                ->label('Duplicar')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Duplicar vaga')
                ->modalDescription('Uma cópia será criada como Rascunho com nova URL. As candidaturas não serão copiadas.')
                ->authorize(fn (): bool => Gate::allows('create', Vacancy::class))
                ->action(function (): void {
                    $record = $this->record;
                    $copy = $record->replicate();
                    $copy->title = $record->title.' (Cópia)';
                    $copy->slug = VacancySlugService::generate($copy->title);
                    $copy->status = VacancyStatus::Draft;
                    $copy->published_at = null;
                    $copy->expires_at = null;
                    $copy->closed_at = null;
                    $copy->attributes['is_active'] = 0;
                    $copy->save();

                    Notification::make()->title('Vaga duplicada como rascunho.')->success()->send();

                    $this->redirect(VacancyResource::getUrl('view', ['record' => $copy]));
                }),
            Action::make('publish')
                ->label('Publicar')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status !== VacancyStatus::Published)
                ->authorize(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    $this->record->update([
                        'status' => VacancyStatus::Published,
                        'published_at' => $this->record->published_at ?? now(),
                        'closed_at' => null,
                    ]);
                    Notification::make()->title('Vaga publicada.')->success()->send();
                    $this->refreshFormData(['status', 'published_at', 'closed_at']);
                }),
            Action::make('pause')
                ->label('Pausar')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === VacancyStatus::Published)
                ->authorize(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    $this->record->update(['status' => VacancyStatus::Paused]);
                    Notification::make()->title('Vaga pausada.')->success()->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('reopen')
                ->label('Reabrir')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record->status, [VacancyStatus::Paused, VacancyStatus::Closed], true))
                ->authorize(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    $this->record->update([
                        'status' => VacancyStatus::Published,
                        'published_at' => $this->record->published_at ?? now(),
                        'closed_at' => null,
                        'expires_at' => null,
                    ]);
                    Notification::make()->title('Vaga reaberta e publicada.')->success()->send();
                    $this->refreshFormData(['status', 'published_at', 'closed_at', 'expires_at']);
                }),
            Action::make('close')
                ->label('Encerrar')
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record->status, [VacancyStatus::Published, VacancyStatus::Paused], true))
                ->authorize(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    $this->record->update(['status' => VacancyStatus::Closed, 'closed_at' => now()]);
                    Notification::make()->title('Vaga encerrada.')->success()->send();
                    $this->refreshFormData(['status', 'closed_at']);
                }),
            Action::make('archive')
                ->label('Arquivar')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status !== VacancyStatus::Archived)
                ->authorize(fn (): bool => Gate::allows('update', $this->record))
                ->action(function (): void {
                    $this->record->update(['status' => VacancyStatus::Archived]);
                    Notification::make()->title('Vaga arquivada.')->success()->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('preview')
                ->label('Visualizar no Site')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => route('site.vacancies.show', $this->record->slug))
                ->openUrlInNewTab(),
            DeleteAction::make()->label('Excluir Vaga')->modalHeading('Excluir Vaga'),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            VacancyApplicationsRelationManager::class,
        ];
    }
}
