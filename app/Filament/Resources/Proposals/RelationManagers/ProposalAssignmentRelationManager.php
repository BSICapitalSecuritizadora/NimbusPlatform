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
                    ->label('Posição na Fila')
                    ->sortable(),
                TextColumn::make('representative.name')
                    ->label('Representante Comercial')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('representative.email')
                    ->label('E-mail do Representante')
                    ->searchable(),
                TextColumn::make('strategy')
                    ->label('Estratégia de Distribuição')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'round_robin' => 'Rodízio (Round-robin)',
                        default => $state ?: '—',
                    }),
                TextColumn::make('assigned_at')
                    ->label('Data de Atribuição')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('assigned_at', 'desc');
    }
}
