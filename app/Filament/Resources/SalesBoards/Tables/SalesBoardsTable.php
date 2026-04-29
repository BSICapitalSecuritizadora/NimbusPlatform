<?php

namespace App\Filament\Resources\SalesBoards\Tables;

use App\Models\SalesBoard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesBoardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('reference_month')
                    ->label('Competência')
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
                    ->label('Valor total')
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
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('construction_id')
                    ->label('Empreendimento')
                    ->relationship('construction', 'development_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reference_month')
                    ->label('Competência')
                    ->options(fn (): array => SalesBoard::query()
                        ->orderByDesc('reference_month')
                        ->pluck('reference_month')
                        ->filter()
                        ->unique()
                        ->mapWithKeys(fn (mixed $referenceMonth): array => [
                            (string) SalesBoard::normalizeReferenceMonth($referenceMonth) => SalesBoard::formatReferenceMonthForDisplay($referenceMonth),
                        ])
                        ->all()),
            ])
            ->defaultSort('reference_month', 'desc')
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
