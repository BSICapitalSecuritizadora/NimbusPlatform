<?php

namespace App\Filament\Resources\Negotiations\Tables;

use App\Models\Negotiation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NegotiationsTable
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
                    ->label('Mês')
                    ->date('m/Y')
                    ->sortable(),

                TextColumn::make('sales')
                    ->label('Vendas')
                    ->sortable(),

                TextColumn::make('cancellations')
                    ->label('Distratos')
                    ->sortable(),
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
                    ->label('Mês')
                    ->options(fn (): array => Negotiation::query()
                        ->orderByDesc('reference_month')
                        ->pluck('reference_month')
                        ->filter()
                        ->unique()
                        ->mapWithKeys(fn (mixed $referenceMonth): array => [
                            (string) Negotiation::normalizeReferenceMonth($referenceMonth) => Negotiation::formatReferenceMonthForDisplay($referenceMonth),
                        ])
                        ->all()),
            ])
            ->defaultSort('reference_month', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
