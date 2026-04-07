<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use App\Enums\ProposalStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProposalStatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Histórico de Status';

    protected static ?string $modelLabel = 'Movimentação';

    protected static ?string $pluralModelLabel = 'Movimentações';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'changedByUser',
            ]))
            ->recordTitleAttribute('new_status')
            ->columns([
                TextColumn::make('previous_status')
                    ->label('Status anterior')
                    ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                    ->badge()
                    ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),
                TextColumn::make('new_status')
                    ->label('Novo status')
                    ->formatStateUsing(fn (?string $state): string => ProposalStatus::labelFor($state))
                    ->badge()
                    ->color(fn (?string $state): string => ProposalStatus::colorFor($state)),
                TextColumn::make('changedByUser.name')
                    ->label('Responsável')
                    ->placeholder('Sistema')
                    ->searchable(),
                TextColumn::make('changed_at')
                    ->label('Alterado em')
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
            ->defaultSort('changed_at', 'desc');
    }
}
