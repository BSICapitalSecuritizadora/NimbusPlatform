<?php

namespace App\Filament\Resources\Recruitment\Tables;

use App\Models\Vacancy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VacanciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Título da Vaga')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department')
                    ->label('Departamento / Área')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Não informado'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('location')
                    ->label('Localização')
                    ->searchable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aberta' : 'Pausada')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('applications_count')
                    ->label('Candidaturas')
                    ->counts('applications')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label('Cadastrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status da Vaga')
                    ->placeholder('Todos')
                    ->trueLabel('Somente Abertas')
                    ->falseLabel('Somente Pausadas'),
                SelectFilter::make('department')
                    ->label('Departamento / Área')
                    ->options(fn (): array => Vacancy::query()
                        ->whereNotNull('department')
                        ->orderBy('department')
                        ->pluck('department', 'department')
                        ->all()),
                SelectFilter::make('type')
                    ->label('Tipo de Contratação')
                    ->options(fn (): array => Vacancy::query()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),
            ])
            ->actions([
                Action::make('toggle_active')
                    ->label(fn (Vacancy $record): string => $record->is_active ? 'Pausar Vaga' : 'Reativar Vaga')
                    ->icon(fn (Vacancy $record): string => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (Vacancy $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Vacancy $record): string => $record->is_active ? 'Pausar Vaga' : 'Reativar Vaga')
                    ->modalDescription(fn (Vacancy $record): string => $record->is_active ? 'Ao pausar a vaga, ela não receberá novas candidaturas pelo site.' : 'Ao reativar a vaga, ela voltará a ficar disponível para candidaturas no site.')
                    ->action(function (Vacancy $record): void {
                        $record->update([
                            'is_active' => ! $record->is_active,
                        ]);

                        Notification::make()
                            ->title($record->is_active ? 'Vaga reativada com sucesso.' : 'Vaga pausada com sucesso.')
                            ->success()
                            ->send();
                    }),
                Action::make('public_page')
                    ->label('Visualizar no Site')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Vacancy $record): string => route('site.vacancies.show', $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (Vacancy $record): bool => $record->is_active),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
