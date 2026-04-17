<?php

namespace App\Filament\Resources\FundNames\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FundNamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fundType.name')
                    ->label('Tipo de fundo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('fund_type_id')
                    ->label('Tipo de fundo')
                    ->relationship('fundType', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
