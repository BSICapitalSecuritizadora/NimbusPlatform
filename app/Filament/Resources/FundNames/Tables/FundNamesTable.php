<?php

namespace App\Filament\Resources\FundNames\Tables;

use App\Filament\Resources\FundNames\FundNameResource;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Resources\FundTypes\FundTypeResource;
use App\Models\FundName;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FundNamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (FundName $record): ?string => FundNameResource::canEdit($record)
                ? FundNameResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(FundName::query()->exists())
            ->searchPlaceholder('Buscar nome de fundo...')
            ->searchDebounce('400ms')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum nome de fundo encontrado'
                : 'Nenhum nome de fundo cadastrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste ou limpe a busca para visualizar os nomes de fundo cadastrados.'
                : 'Cadastre o primeiro nome para vinculá-lo a um tipo de fundo.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeiro nome de fundo')
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => ! static::hasActiveFiltersOrSearch($livewire)),

                Action::make('clear_table_search')
                    ->label('Limpar busca')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveFiltersOrSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableSearch();
                        if (method_exists($livewire, 'resetTableFiltersForm')) {
                            $livewire->resetTableFiltersForm();
                        }
                    }),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (FundName $record): ?string => $record->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fundType.name')
                    ->label('Tipo de fundo')
                    ->wrap()
                    ->lineClamp(1)
                    ->tooltip(fn (FundName $record): ?string => $record->fundType?->name)
                    ->searchable()
                    ->sortable()
                    ->url(fn (FundName $record): ?string => $record->fundType && FundTypeResource::canEdit($record->fundType)
                        ? FundTypeResource::getUrl('edit', ['record' => $record->fundType])
                        : null),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '—',
                        1 => '1 fundo',
                        default => "{$state} fundos",
                    })
                    ->color(fn (int $state): ?string => $state === 0 ? 'gray' : null)
                    ->url(fn (FundName $record): ?string => $record->funds_count > 0
                        ? FundResource::getUrl('index', ['tableFilters' => ['fund_type_id' => ['value' => $record->fund_type_id]]])
                        : null),
            ])
            ->filters([
                SelectFilter::make('fund_type_id')
                    ->label('Tipo de fundo')
                    ->relationship('fundType', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-m-pencil-square'),
                    DeleteAction::make()
                        ->label('Excluir'),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações')
                    ->dropdownWidth(Width::ExtraSmall),
            ]);
    }

    protected static function hasActiveFiltersOrSearch(mixed $livewire): bool
    {
        if (! method_exists($livewire, 'getTableSearch') && ! property_exists($livewire, 'tableSearch')) {
            return false;
        }

        $search = method_exists($livewire, 'getTableSearch')
            ? $livewire->getTableSearch()
            : ($livewire->tableSearch ?? null);

        if (filled($search)) {
            return true;
        }

        if (method_exists($livewire, 'getTableFiltersForm')) {
            $rawState = $livewire->getTableFiltersForm()->getRawState();

            foreach ($rawState as $filterState) {
                if (is_array($filterState)) {
                    if (collect($filterState)->filter(fn ($v): bool => filled($v))->isNotEmpty()) {
                        return true;
                    }
                } elseif (filled($filterState)) {
                    return true;
                }
            }
        }

        return false;
    }
}
