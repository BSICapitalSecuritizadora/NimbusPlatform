<?php

namespace App\Filament\Resources\ExpenseServiceProviderTypes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpenseServiceProviderTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('service_providers_count')
                    ->label('Prestadores vinculados')
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
