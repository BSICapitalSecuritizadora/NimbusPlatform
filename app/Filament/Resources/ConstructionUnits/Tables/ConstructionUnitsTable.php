<?php

namespace App\Filament\Resources\ConstructionUnits\Tables;

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConstructionUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ConstructionUnit $record): ?string => ConstructionUnitResource::canView($record)
                ? ConstructionUnitResource::getUrl('view', ['record' => $record])
                : null)
            ->searchable(ConstructionUnit::query()->exists())
            ->searchPlaceholder('Buscar por unidade, bloco, empreendimento ou emissão...')
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('construction.emission.name')
                    ->label('Contexto')
                    ->description(fn (ConstructionUnit $record): ?string => $record->construction?->development_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        $q->whereHas('construction', fn (Builder $cq): Builder => $cq->where('development_name', 'like', "%{$search}%")
                            ->orWhereHas('emission', fn (Builder $eq): Builder => $eq->where('name', 'like', "%{$search}%")));
                    }))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Emission::select('name')
                            ->join('constructions', 'constructions.emission_id', '=', 'emissions.id')
                            ->whereColumn('constructions.id', 'construction_units.construction_id')
                            ->limit(1),
                        $direction
                    ))
                    ->wrap()
                    ->lineClamp(2)
                    ->toggleable(),

                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('block')
                    ->label('Bloco')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('unit')
                    ->label('Unidade')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->extraAttributes([
                        'class' => 'font-semibold tracking-tight text-white tabular-nums',
                    ]),

                /**
                 * Ocultas por padrão: a tabela é usada para localizar unidades,
                 * e uma coluna de dinheiro sempre visível competiria com a
                 * identificação sem ajudar a encontrar nada.
                 */
                TextColumn::make('base_value')
                    ->label('Valor base')
                    ->money('BRL')
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->placeholder('Não informado')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('base_value_reference_date')
                    ->label('Referência do valor base')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Cadastrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Medium)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('emission')
                    ->label('Emissão')
                    ->options(fn (): array => Emission::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $emissionId): Builder => $query->forEmission($emissionId),
                    )),

                SelectFilter::make('construction_id')
                    ->label('Empreendimento')
                    ->options(fn (Table $table): array => Construction::query()
                        ->when(
                            $table->getLivewire()->getTableFilterState('emission')['value'] ?? null,
                            fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                        )
                        ->orderBy('development_name')
                        ->pluck('development_name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('block')
                    ->label('Bloco')
                    ->options(function (Table $table): array {
                        $emissionFilter = $table->getLivewire()->getTableFilterState('emission')['value'] ?? null;
                        $constructionFilter = $table->getLivewire()->getTableFilterState('construction_id')['value'] ?? null;

                        return ConstructionUnit::query()
                            ->when($emissionFilter, fn (Builder $query, mixed $emissionId): Builder => $query->forEmission($emissionId))
                            ->when($constructionFilter, fn (Builder $query, mixed $constructionId): Builder => $query->where('construction_id', $constructionId))
                            ->whereNotNull('block')
                            ->where('block', '!=', '')
                            ->distinct()
                            ->orderBy('block')
                            ->pluck('block', 'block')
                            ->all();
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (ConstructionUnit $record): bool => ConstructionUnitResource::canView($record)),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (ConstructionUnit $record): bool => ConstructionUnitResource::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->modalHeading('Excluir unidade')
                        ->visible(fn (ConstructionUnit $record): bool => ConstructionUnitResource::canDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da unidade'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma unidade encontrada'
                : 'Nenhuma unidade cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as unidades cadastradas.'
                : 'Cadastre manualmente ou importe uma planilha para iniciar.')
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Nova unidade')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->visible(fn ($livewire): bool => ! static::hasActiveFiltersOrSearch($livewire) && ConstructionUnitResource::canCreate()),

                Action::make('clear_table_filters')
                    ->label('Limpar filtros')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveFiltersOrSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableSearch();
                        $livewire->resetTableFiltersForm();
                    }),
            ]);
    }

    /**
     * Verifica se há busca ou filtros ativos na tabela para alternar o estado vazio.
     */
    protected static function hasActiveFiltersOrSearch($livewire): bool
    {
        if (filled($livewire->tableSearch ?? null)) {
            return true;
        }

        $hasValue = function (mixed $value) use (&$hasValue): bool {
            if (is_array($value)) {
                return collect($value)->contains(fn (mixed $item): bool => $hasValue($item));
            }

            return filled($value);
        };

        return collect($livewire->tableFilters ?? [])->contains(fn (mixed $state): bool => $hasValue($state));
    }
}
