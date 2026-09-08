<?php

namespace App\Filament\Resources\SalesBoardCycles\Tables;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Filament\Resources\SalesBoardCycles\Actions\CheckSalesBoardCycleStaleAction;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\SalesBoardCycle;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesBoardCyclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (SalesBoardCycle $record): ?string => SalesBoardCycleResource::canView($record)
                ? SalesBoardCycleResource::getUrl('view', ['record' => $record])
                : null)
            ->searchPlaceholder('Buscar por emissão ou empreendimento...')
            ->defaultSort('reference_month', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum ciclo congelado')
            ->emptyStateDescription('Congele uma competência para registrar o que o Nimbus apurou nela.')
            ->emptyStateIcon('heroicon-o-camera')
            ->columns([
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->description(fn (SalesBoardCycle $record): ?string => $record->emission?->name)
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('construction', fn (Builder $inner): Builder => $inner
                            ->where('development_name', 'like', "%{$search}%"))
                        ->orWhereHas('emission', fn (Builder $inner): Builder => $inner
                            ->where('name', 'like', "%{$search}%")))
                    ->sortable(),

                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('position_date')
                    ->label('Posição em')
                    ->date('d/m/Y')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (SalesBoardCycleStatus $state): string => $state->label())
                    ->color(fn (SalesBoardCycleStatus $state): string => $state->color()),

                TextColumn::make('currentBaseline.version')
                    ->label('Versão')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : 'V'.$state)
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('currentBaseline.stale_impact')
                    ->label('Fonte')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?SalesBoardStaleImpact $state): string => ($state ?? SalesBoardStaleImpact::None)->label())
                    ->color(fn (?SalesBoardStaleImpact $state): string => ($state ?? SalesBoardStaleImpact::None)->color())
                    ->tooltip(fn (SalesBoardCycle $record): ?string => $record->currentBaseline?->last_checked_at
                        ?->format('\Ú\l\t\i\m\a \v\e\r\i\f\i\c\a\ç\ã\o\: d/m/Y \à\s H:i')),

                TextColumn::make('currentBaseline.units_total')
                    ->label('Unidades')
                    ->description(fn (SalesBoardCycle $record): ?string => $record->currentBaseline === null
                        ? null
                        : sprintf(
                            '%d estoque · %d financiadas · %d quitadas · %d permutadas',
                            $record->currentBaseline->stock_units,
                            $record->currentBaseline->financed_units,
                            $record->currentBaseline->settled_units,
                            $record->currentBaseline->exchanged_units,
                        ))
                    ->alignEnd()
                    ->weight('semibold'),

                TextColumn::make('currentBaseline.stock_value')
                    ->label('Valor em Estoque')
                    ->money('BRL')
                    ->placeholder('indisponível')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('currentBaseline.computed_at')
                    ->label('Gerado em')
                    ->dateTime('d/m/Y H:i')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filtersFormWidth(Width::Small)
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
                    ->options(fn (): array => SalesBoardCycle::query()
                        ->orderByDesc('reference_month')
                        ->pluck('reference_month')
                        ->filter()
                        ->map(fn (mixed $month): string => SalesBoardCycle::normalizeReferenceMonth($month)->toDateString())
                        ->unique()
                        ->mapWithKeys(fn (string $month): array => [
                            $month => SalesBoardCycle::normalizeReferenceMonth($month)->format('m/Y'),
                        ])
                        ->all()),

                TernaryFilter::make('is_stale')
                    ->label('Fonte alterada')
                    ->placeholder('Todos os ciclos')
                    ->trueLabel('Com alteração na fonte')
                    ->falseLabel('Sem alteração na fonte')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas(
                            'currentBaseline',
                            fn (Builder $inner): Builder => $inner->where('is_stale', true),
                        ),
                        false: fn (Builder $query): Builder => $query->whereHas(
                            'currentBaseline',
                            fn (Builder $inner): Builder => $inner->where('is_stale', false),
                        ),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary'),

                    CheckSalesBoardCycleStaleAction::make(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do ciclo'),
            ])
            /**
             * Sem ações em massa: um snapshot financeiro não pode ser apagado --
             * nem um, nem cinquenta de uma vez.
             */
            ->toolbarActions([]);
    }
}
