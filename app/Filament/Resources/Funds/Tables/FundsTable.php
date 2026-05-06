<?php

namespace App\Filament\Resources\Funds\Tables;

use App\Models\Fund;
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
                    ->label('Operacao')
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
                    ->label('Aplicacao')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('bank.name')
                    ->label('Banco')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('agency')
                    ->label('Agencia')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account')
                    ->label('Conta Corrente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('minimum_balance')
                    ->label('Valor minimo')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('balance_status')
                    ->label('Status do saldo')
                    ->state(fn (Fund $record): string => $record->requiresMonthlyBalanceUpdate() ? 'Atualizacao pendente' : 'Em dia')
                    ->badge()
                    ->color(fn (Fund $record): string => $record->requiresMonthlyBalanceUpdate() ? 'warning' : 'success'),

                TextColumn::make('balance_updated_at')
                    ->label('Saldo atualizado em')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Operacao')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('fund_type_id')
                    ->label('Tipo de fundo')
                    ->relationship('fundType', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('fund_application_id')
                    ->label('Aplicacao')
                    ->relationship('fundApplication', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('bank_id')
                    ->label('Banco')
                    ->relationship('bank', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('account')
                    ->label('Conta corrente')
                    ->options(fn (): array => Fund::query()
                        ->orderBy('account')
                        ->pluck('account', 'account')
                        ->all())
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
