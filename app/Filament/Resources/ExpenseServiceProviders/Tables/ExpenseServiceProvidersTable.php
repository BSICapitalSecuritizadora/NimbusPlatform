<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Tables;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use App\Models\ExpenseServiceProvider;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ExpenseServiceProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ExpenseServiceProvider $record): ?string => ExpenseServiceProviderResource::canEdit($record)
                ? ExpenseServiceProviderResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(ExpenseServiceProvider::query()->exists())
            ->searchPlaceholder('Buscar por nome ou CNPJ...')
            ->searchDebounce('400ms')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum prestador encontrado'
                : 'Nenhum prestador cadastrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar os prestadores cadastrados.'
                : 'Cadastre o primeiro prestador para utilizá-lo nas despesas e participantes das operações.')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Cadastrar primeiro prestador')
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => ! static::hasActiveFiltersOrSearch($livewire)),

                Action::make('clear_table_filters')
                    ->label('Limpar filtros')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveFiltersOrSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableSearch();
                        $livewire->resetTableFiltersForm();
                    }),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Prestador')
                    ->formatStateUsing(fn ($state, ExpenseServiceProvider $record): HtmlString => static::renderProviderCell($record))
                    ->html()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $cleanSearch = preg_replace('/[^\d]/', '', $search);

                        return $query->where(function (Builder $sub) use ($search, $cleanSearch) {
                            $sub->where('name', 'like', "%{$search}%");
                            if (filled($cleanSearch)) {
                                $sub->orWhere('cnpj', 'like', "%{$cleanSearch}%");
                            }
                        });
                    })
                    ->sortable()
                    ->wrap(),

                TextColumn::make('type.name')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('expenses_count')
                    ->label('Despesas vinculadas')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '0 despesas',
                        1 => '1 despesa',
                        default => "{$state} despesas",
                    })
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->url(fn (ExpenseServiceProvider $record): ?string => $record->expenses_count > 0
                        ? ExpenseResource::getUrl('index', ['tableFilters' => ['expense_service_provider_id' => ['value' => $record->getKey()]]])
                        : null)
                    ->tooltip(fn (ExpenseServiceProvider $record): ?string => $record->expenses_count > 0 ? 'Ver despesas vinculadas a este prestador' : null)
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('expense_service_provider_type_id')
                    ->label('Tipo')
                    ->relationship('type', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-m-pencil-square'),
                    DeleteAction::make()
                        ->label('Excluir')
                        ->visible(fn (ExpenseServiceProvider $record): bool => ExpenseServiceProviderResource::canDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações')
                    ->dropdownWidth(Width::ExtraSmall),
            ]);
    }

    protected static function renderProviderCell(ExpenseServiceProvider $record): HtmlString
    {
        $name = e($record->name);
        $cnpj = $record->formatted_cnpj ?? ExpenseServiceProvider::formatCnpj($record->cnpj);
        $formattedCnpj = filled($cnpj) ? e($cnpj) : '—';

        return new HtmlString('
            <div class="flex flex-col gap-0.5 py-0.5 max-w-xl">
                <span class="font-semibold text-slate-900 dark:text-[#fbfaf8] leading-snug line-clamp-2" title="'.$name.'">'.$name.'</span>
                <span class="text-xs text-slate-500 dark:text-slate-400 font-mono tracking-tight">'.$formattedCnpj.'</span>
            </div>
        ');
    }

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
