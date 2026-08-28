<?php

namespace App\Filament\Resources\IndexRates\Tables;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
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
            ->heading('Histórico dos índices')
            ->description('Valores publicados e projetados utilizados nos cálculos e curvas da plataforma.')
            ->columns([
                TextColumn::make('indexer')
                    ->label('Indexador')
                    ->badge()
                    ->weight('semibold')
                    ->color(fn ($state): string => match ($state) {
                        'CDI' => 'info',
                        'IPCA' => 'warning',
                        'PRE' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn ($state): string => match ($state) {
                        'CDI' => 'heroicon-o-chart-bar',
                        'IPCA' => 'heroicon-o-scale',
                        'PRE' => 'heroicon-o-banknotes',
                        default => 'heroicon-o-table-cells',
                    })
                    ->sortable(),

                TextColumn::make('rate_date')
                    ->label('Data de referência')
                    ->date('d/m/Y')
                    ->fontFamily('mono')
                    ->sortable(),

                TextColumn::make('rate_value')
                    ->label('Valor')
                    ->formatStateUsing(function ($state, IndexRate $record): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }

                        $num = (float) $state;
                        $formatted = number_format($num, 8, ',', '.');

                        if ($record->indexer === 'CDI') {
                            return $formatted.' % a.a.';
                        }

                        return $formatted;
                    })
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('is_projected')
                    ->label('Origem')
                    ->badge()
                    ->state(fn (IndexRate $record): string => $record->isProjectedRate() ? 'Projetado' : 'Publicado')
                    ->color(fn (IndexRate $record): string => $record->isProjectedRate() ? 'warning' : 'success')
                    ->icon(fn (IndexRate $record): string => $record->isProjectedRate() ? 'heroicon-o-presentation-chart-line' : 'heroicon-o-check-badge'),

                TextColumn::make('projectionSeries.status')
                    ->label('Série projetada')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Fonte')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'bcb_sgs' => 'Banco Central',
                        'manual_import' => 'Importação manual',
                        'anbima' => 'ANBIMA',
                        'b3' => 'B3',
                        default => (string) ($state ?? '—'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'bcb_sgs' => 'info',
                        'manual_import' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('external_series_code')
                    ->label('Série SGS')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('fetched_at')
                    ->label('Sincronizado em')
                    ->dateTime('d/m/Y · H:i')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('projection_source')
                    ->label('Fonte projeção')
                    ->placeholder('—')
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

                SelectFilter::make('source')
                    ->label('Fonte')
                    ->options([
                        'bcb_sgs' => 'Banco Central (SGS)',
                        'manual_import' => 'Importação manual',
                        'anbima' => 'ANBIMA',
                        'b3' => 'B3',
                    ]),

                SelectFilter::make('is_projected')
                    ->label('Origem')
                    ->options([
                        '0' => 'Publicados',
                        '1' => 'Projetados',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        '0' => $query->where('is_projected', false),
                        '1' => $query->where('is_projected', true),
                        default => $query,
                    }),

                Filter::make('rate_date')
                    ->label('Data de referência')
                    ->form([
                        DatePicker::make('rate_from')
                            ->label('A partir de'),
                        DatePicker::make('rate_until')
                            ->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['rate_from'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('rate_date', '>=', $date),
                            )
                            ->when(
                                $data['rate_until'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('rate_date', '<=', $date),
                            );
                    }),
            ])
            ->emptyStateHeading('Nenhum índice registrado')
            ->emptyStateDescription('Sincronize ou importe índices econômicos para acompanhar seus valores históricos e projetados.')
            ->emptyStateIcon(Heroicon::OutlinedTableCells)
            ->searchPlaceholder('Buscar por indexador, fonte ou série...')
            ->searchOnBlur(false)
            ->striped()
            ->defaultSort('rate_date', 'desc')
            ->recordActions([]);
    }
}
