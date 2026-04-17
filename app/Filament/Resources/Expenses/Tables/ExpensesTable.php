<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\Expense;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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

                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
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
                SelectFilter::make('emission_id')
                    ->label('Operação')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(Expense::CATEGORY_OPTIONS),
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
