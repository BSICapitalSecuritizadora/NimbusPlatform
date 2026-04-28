<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpenseServiceProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type.name')
                    ->label('Tipo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cnpj')
                    ->label('CNPJ')
                    ->formatStateUsing(fn (?string $state): string => \App\Models\ExpenseServiceProvider::formatCnpj($state))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('expenses_count')
                    ->label('Despesas vinculadas')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
