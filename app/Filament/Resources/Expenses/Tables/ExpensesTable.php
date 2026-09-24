<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Expense $record): ?string => ExpenseResource::canEdit($record)
                ? ExpenseResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(Expense::query()->exists())
            ->searchPlaceholder('Buscar por categoria, prestador ou operação...')
            ->searchDebounce('400ms')
            ->defaultSort('start_date', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma despesa corresponde aos filtros selecionados'
                : 'Nenhuma despesa cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as despesas cadastradas.'
                : 'Cadastre a primeira despesa para acompanhar os custos e vencimentos da operação.')
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Cadastrar primeira despesa')
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
                TextColumn::make('emission.name')
                    ->label('Operação')
                    ->color('gray')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Expense $record): ?string => $record->emission?->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable(),

                TextColumn::make('serviceProvider.name')
                    ->label('Prestador de serviço')
                    ->placeholder('Não informado')
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Valor')
                    ->state(function (Expense $record): float {
                        $latestHistory = $record->histories()
                            ->orderByDesc('due_date')
                            ->first();

                        if ($latestHistory !== null) {
                            return (float) $record->histories()
                                ->whereYear('due_date', $latestHistory->due_date->year)
                                ->whereMonth('due_date', $latestHistory->due_date->month)
                                ->sum('amount');
                        }

                        return (float) $record->amount;
                    })
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('period')
                    ->label('Período')
                    ->formatStateUsing(fn (string $state): string => Expense::PERIOD_OPTIONS[$state] ?? $state)
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('start_date')
                    ->label('Vencimento / Início')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Término')
                    ->state(fn (Expense $record): string => $record->end_date?->format('d/m/Y')
                        ?? (Expense::isRecurringPeriod($record->period) ? 'Sem término' : '—'))
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Operação')
                    ->multiple()
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
                SelectFilter::make('expense_service_provider_id')
                    ->label('Prestador')
                    ->relationship('serviceProvider', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(Expense::CATEGORY_OPTIONS),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma despesa cadastrada" do "nenhuma corresponde aos filtros".
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
