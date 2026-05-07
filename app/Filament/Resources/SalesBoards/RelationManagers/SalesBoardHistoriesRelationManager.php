<?php

namespace App\Filament\Resources\SalesBoards\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalesBoardHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'valueHistories';

    protected static ?string $title = 'Historico de valores';

    protected static ?string $modelLabel = 'Historico de valores';

    protected static ?string $pluralModelLabel = 'Historico de valores';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Registrado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('reference_month')
                    ->label('Competencia')
                    ->date('m/Y')
                    ->sortable(),
                TextColumn::make('stock_units')
                    ->label('Estoque')
                    ->sortable(),
                TextColumn::make('financed_units')
                    ->label('Financiado')
                    ->sortable(),
                TextColumn::make('paid_units')
                    ->label('Quitado')
                    ->sortable(),
                TextColumn::make('exchanged_units')
                    ->label('Permutado')
                    ->sortable(),
                TextColumn::make('total_units')
                    ->label('Total')
                    ->sortable(),
                TextColumn::make('stock_value')
                    ->label('Valor em estoque')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('financed_value')
                    ->label('Valor financiado')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('paid_value')
                    ->label('Valor quitado')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('exchanged_value')
                    ->label('Valor permutado')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Nenhum historico de valores registrado');
    }
}
