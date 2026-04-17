<?php

namespace App\Filament\Resources\FundTypes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fund_names_count')
                    ->label('Nomes vinculados')
                    ->badge(),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
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
