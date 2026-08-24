<?php

namespace App\Filament\Resources\Recruitment\RelationManagers;

use App\Models\JobApplication;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JobApplicationStatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Histórico de Movimentações';

    protected static ?string $modelLabel = 'Movimentação';

    protected static ?string $pluralModelLabel = 'Movimentações';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['changedBy']))
            ->recordTitleAttribute('to_status')
            ->columns([
                TextColumn::make('from_status')
                    ->label('De')
                    ->formatStateUsing(fn (?string $state): string => $state ? JobApplication::statusLabelFor($state) : '—')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? JobApplication::statusColorFor($state) : 'gray'),
                TextColumn::make('to_status')
                    ->label('Para')
                    ->formatStateUsing(fn (?string $state): string => JobApplication::statusLabelFor($state))
                    ->badge()
                    ->color(fn (?string $state): string => JobApplication::statusColorFor($state)),
                TextColumn::make('changedBy.name')
                    ->label('Responsável')
                    ->placeholder('Sistema')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('note')
                    ->label('Observação')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhuma movimentação registrada')
            ->emptyStateDescription('As alterações de status aparecerão aqui em ordem cronológica.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
