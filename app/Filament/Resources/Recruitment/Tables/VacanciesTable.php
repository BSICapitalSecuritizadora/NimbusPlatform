<?php

namespace App\Filament\Resources\Recruitment\Tables;

use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Filament\Resources\Recruitment\VacancyResource;
use App\Models\Vacancy;
use App\Services\Recruitment\VacancySlugService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class VacanciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Vacancy $record): string => VacancyResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->label('Título da Vaga')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => VacancyStatus::labelFor($state))
                    ->color(fn ($state): string => VacancyStatus::colorFor($state))
                    ->sortable(),
                TextColumn::make('department')
                    ->label('Departamento')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->placeholder('Geral'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => VacancyEmploymentType::labelFor($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('work_model')
                    ->label('Modelo')
                    ->badge()
                    ->color(fn (?string $state): string => VacancyWorkModel::fromValue($state)?->color() ?? 'gray')
                    ->formatStateUsing(fn (?string $state): string => VacancyWorkModel::labelFor($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('location')
                    ->label('Localização')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('positions')
                    ->label('Vagas')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('applications_count')
                    ->label('Candidaturas')
                    ->counts('applications')
                    ->badge()
                    ->color('gray')
                    ->url(fn (Vacancy $record): string => JobApplicationResource::getUrl('index', [
                        'tableFilters[vacancy_id][value]' => $record->getKey(),
                    ]))
                    ->sortable(),
                TextColumn::make('hiringManager.name')
                    ->label('Gestor')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('expires_at')
                    ->label('Expira em')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Cadastrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(VacancyStatus::options())
                    ->multiple(),
                SelectFilter::make('department')
                    ->label('Departamento')
                    ->options(VacancyDepartment::options())
                    ->multiple(),
                SelectFilter::make('type')
                    ->label('Tipo de Contratação')
                    ->options(VacancyEmploymentType::options())
                    ->multiple(),
                SelectFilter::make('work_model')
                    ->label('Modelo de Trabalho')
                    ->options(VacancyWorkModel::options())
                    ->multiple(),
                SelectFilter::make('hiring_manager_id')
                    ->label('Gestor Responsável')
                    ->relationship('hiringManager', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('duplicate')
                    ->label('Duplicar')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Duplicar vaga')
                    ->modalDescription('Uma cópia será criada como Rascunho com nova URL. As candidaturas não serão copiadas.')
                    ->authorize(fn (Vacancy $record): bool => Gate::allows('create', Vacancy::class))
                    ->action(function (Vacancy $record): void {
                        $copy = $record->replicate();
                        $copy->title = $record->title.' (Cópia)';
                        $copy->slug = VacancySlugService::generate($copy->title);
                        $copy->status = VacancyStatus::Draft;
                        $copy->published_at = null;
                        $copy->expires_at = null;
                        $copy->closed_at = null;
                        $copy->is_active = false; // keeps legacy column sync
                        $copy->save();

                        Notification::make()
                            ->title('Vaga duplicada como rascunho.')
                            ->success()
                            ->send();
                    }),
                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Vacancy $record): bool => $record->status !== VacancyStatus::Published)
                    ->authorize(fn (Vacancy $record): bool => Gate::allows('update', $record))
                    ->action(function (Vacancy $record): void {
                        $record->update([
                            'status' => VacancyStatus::Published,
                            'published_at' => $record->published_at ?? now(),
                            'closed_at' => null,
                        ]);

                        Notification::make()->title('Vaga publicada com sucesso.')->success()->send();
                    }),
                Action::make('pause')
                    ->label('Pausar')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Vacancy $record): bool => $record->status === VacancyStatus::Published)
                    ->authorize(fn (Vacancy $record): bool => Gate::allows('update', $record))
                    ->action(function (Vacancy $record): void {
                        $record->update(['status' => VacancyStatus::Paused]);
                        Notification::make()->title('Vaga pausada com sucesso.')->success()->send();
                    }),
                Action::make('reopen')
                    ->label('Reabrir')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Vacancy $record): bool => in_array($record->status, [VacancyStatus::Paused, VacancyStatus::Closed], true))
                    ->authorize(fn (Vacancy $record): bool => Gate::allows('update', $record))
                    ->action(function (Vacancy $record): void {
                        $record->update([
                            'status' => VacancyStatus::Published,
                            'published_at' => $record->published_at ?? now(),
                            'closed_at' => null,
                            'expires_at' => null,
                        ]);
                        Notification::make()->title('Vaga reaberta e publicada.')->success()->send();
                    }),
                Action::make('close')
                    ->label('Encerrar')
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Vacancy $record): bool => in_array($record->status, [VacancyStatus::Published, VacancyStatus::Paused], true))
                    ->authorize(fn (Vacancy $record): bool => Gate::allows('update', $record))
                    ->action(function (Vacancy $record): void {
                        $record->update([
                            'status' => VacancyStatus::Closed,
                            'closed_at' => now(),
                        ]);
                        Notification::make()->title('Vaga encerrada.')->success()->send();
                    }),
                Action::make('preview')
                    ->label('Visualizar no Site')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Vacancy $record): string => route('site.vacancies.show', $record->slug))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkAction::make('archive')
                    ->label('Arquivar')
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->can('recruitment.vacancies.update') ?? false)
                    ->action(function (Collection $records): void {
                        $records->each(fn (Vacancy $v) => $v->update(['status' => VacancyStatus::Archived]));
                        Notification::make()->title($records->count().' vaga(s) arquivada(s).')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nenhuma vaga encontrada')
            ->emptyStateDescription('Não há vagas cadastradas nesta etapa ou que correspondam aos filtros aplicados.')
            ->emptyStateIcon('heroicon-o-briefcase')
            ->emptyStateActions([
                Action::make('create')
                    ->label('Cadastrar Vaga')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => VacancyResource::getUrl('create'))
                    ->visible(fn (): bool => Gate::allows('create', Vacancy::class)),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
