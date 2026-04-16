<?php

namespace App\Filament\Resources\Payments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('premium_value')
                    ->label('Prêmio')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('interest_value')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('amortization_value')
                    ->label('Amortização')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('extra_amortization_value')
                    ->label('Amortização extra')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('payment_date', 'desc')
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
