<?php

namespace App\Filament\Resources\Proposals\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProposalAssignmentRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Histórico de Distribuição';

    protected static ?string $modelLabel = 'Distribuição';

    protected static ?string $pluralModelLabel = 'Distribuições';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'representative',
            ]))
            ->recordTitleAttribute('sequence')
            ->columns([
                TextColumn::make('sequence')
                    ->label('# Fila')
                    ->sortable(),
                TextColumn::make('representative.name')
                    ->label('Representante')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('representative.email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('strategy')
                    ->label('Estratégia')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'round_robin' => 'Round-robin',
                        default => $state ?: '—',
                    }),
                TextColumn::make('assigned_at')
                    ->label('Atribuída em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('assigned_at', 'desc');
    }
}
