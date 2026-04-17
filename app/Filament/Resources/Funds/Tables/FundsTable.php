<?php

namespace App\Filament\Resources\Funds\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Operação')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fundType.name')
                    ->label('Tipo de fundo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fundName.name')
                    ->label('Nome do fundo')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('fundApplication.name')
                    ->label('Aplicação')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('bank.name')
                    ->label('Banco')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Operação')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('fund_type_id')
                    ->label('Tipo de fundo')
                    ->relationship('fundType', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('fund_application_id')
                    ->label('Aplicação')
                    ->relationship('fundApplication', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('bank_id')
                    ->label('Banco')
                    ->relationship('bank', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
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
