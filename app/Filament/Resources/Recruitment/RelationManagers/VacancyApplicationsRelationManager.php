<?php

namespace App\Filament\Resources\Recruitment\RelationManagers;

use App\Filament\Resources\Recruitment\JobApplicationResource;
use App\Models\JobApplication;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VacancyApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    protected static ?string $title = 'Candidaturas desta Vaga';

    protected static ?string $modelLabel = 'Candidatura';

    protected static ?string $pluralModelLabel = 'Candidaturas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['vacancy', 'reviewedBy']))
            ->columns([
                TextColumn::make('name')->label('Candidato')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state))
                    ->color(fn (?string $state): string => JobApplication::statusColorFor($state))
                    ->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable()->toggleable(),
                TextColumn::make('created_at')->label('Recebida em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('reviewedBy.name')->label('Movimentada por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->actions([
                ViewAction::make()->url(fn (JobApplication $record): string => JobApplicationResource::getUrl('view', ['record' => $record])),
                EditAction::make()->url(fn (JobApplication $record): string => JobApplicationResource::getUrl('edit', ['record' => $record])),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhuma candidatura para esta vaga')
            ->emptyStateDescription('Quando candidatos se inscreverem nesta vaga, eles aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-user-group');
    }
}
