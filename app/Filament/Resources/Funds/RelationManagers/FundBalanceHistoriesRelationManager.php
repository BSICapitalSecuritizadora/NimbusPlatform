<?php

namespace App\Filament\Resources\Funds\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundBalanceHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'balanceHistories';

    protected static ?string $title = 'Historico de saldo';

    protected static ?string $modelLabel = 'Historico de saldo';

    protected static ?string $pluralModelLabel = 'Historico de saldo';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Nenhum historico de saldo registrado');
    }
}
