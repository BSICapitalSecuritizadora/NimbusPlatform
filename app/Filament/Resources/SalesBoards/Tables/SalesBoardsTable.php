<?php

namespace App\Filament\Resources\SalesBoards\Tables;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesBoardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (SalesBoard $record): ?string => SalesBoardResource::canView($record)
                ? SalesBoardResource::getUrl('view', ['record' => $record])
                : null)
            ->searchPlaceholder('Buscar por emissão ou empreendimento...')
            ->defaultSort('reference_month', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum quadro de vendas encontrado')
            ->emptyStateDescription('Não há quadros de vendas cadastrados ou que correspondam aos filtros aplicados.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->columns([
                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->description(fn (SalesBoard $record): ?string => $record->emission?->name)
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('construction', fn (Builder $constructionQuery): Builder => $constructionQuery
                            ->where('development_name', 'like', "%{$search}%"))
                        ->orWhereHas('emission', fn (Builder $emissionQuery): Builder => $emissionQuery
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

                TextColumn::make('total_units')
                    ->label('Unidades')
                    ->description(fn (SalesBoard $record): string => "{$record->stock_units} estoque · {$record->financed_units} financiadas · {$record->paid_units} quitadas · {$record->exchanged_units} permutadas")
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('stock_value')
                    ->label('Valor em Estoque')
                    ->money('BRL')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('stock_units')
                    ->label('Estoque (un.)')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('financed_units')
                    ->label('Financiadas (un.)')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('paid_units')
                    ->label('Quitadas (un.)')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('exchanged_units')
                    ->label('Permutadas (un.)')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('financed_value')
                    ->label('Valor Financiado')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('paid_value')
                    ->label('Valor Quitado')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('exchanged_value')
                    ->label('Valor Permutado')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
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

                TernaryFilter::make('stock_position')
                    ->label('Estoque')
                    ->placeholder('Todos os quadros')
                    ->trueLabel('Com estoque')
                    ->falseLabel('Sem estoque')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('stock_units', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('stock_units', '<=', 0),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (SalesBoard $record): bool => SalesBoardResource::canView($record)),

                    Action::make('newUpdate')
                        ->label('Nova Atualização')
                        ->icon('heroicon-o-plus')
                        ->visible(fn (SalesBoard $record): bool => SalesBoardResource::canEdit($record))
                        ->url(fn (SalesBoard $record): string => SalesBoardResource::getUrl('create', [
                            'from' => $record->getKey(),
                        ])),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do quadro'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
