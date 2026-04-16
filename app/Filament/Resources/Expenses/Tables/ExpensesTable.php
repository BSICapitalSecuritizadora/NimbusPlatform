<?php

namespace App\Filament\Resources\Expenses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Operação')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('serviceProvider.name')
                    ->label('Prestador de serviço')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('period')
                    ->label('Período')
                    ->formatStateUsing(fn (string $state): string => \App\Models\Expense::PERIOD_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->sortable(),

                TextColumn::make('start_date')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Fim')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
