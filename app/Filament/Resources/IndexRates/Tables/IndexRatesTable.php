<?php

namespace App\Filament\Resources\IndexRates\Tables;

use App\Domain\PuCalculator\Enums\PuIndexer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IndexRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('indexer')
                    ->label('Indexador')
                    ->badge()
                    ->sortable(),
                TextColumn::make('rate_date')
                    ->label('Data de referência')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('rate_value')
                    ->label('Valor')
                    ->sortable(),
                TextColumn::make('is_projected')
                    ->label('Origem')
                    ->badge()
                    ->state(fn ($record): string => $record->is_projected ? 'Projetado' : 'Publicado')
                    ->color(fn ($record): string => $record->is_projected ? 'warning' : 'success'),
                TextColumn::make('projectionSeries.status')
                    ->label('Série projetada')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label('Fonte')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('projection_source')
                    ->label('Fonte projeção')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('indexer')
                    ->label('Indexador')
                    ->options([
                        PuIndexer::Cdi->value => PuIndexer::Cdi->value,
                        PuIndexer::Ipca->value => PuIndexer::Ipca->value,
                        PuIndexer::Prefixed->value => PuIndexer::Prefixed->value,
                    ]),
                Filter::make('is_projected')
                    ->label('Somente projetados')
                    ->query(fn (Builder $query): Builder => $query->where('is_projected', true)),
            ])
            ->defaultSort('rate_date', 'desc')
            ->recordActions([]);
    }
}
