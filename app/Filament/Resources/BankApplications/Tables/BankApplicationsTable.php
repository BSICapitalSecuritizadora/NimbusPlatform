<?php

namespace App\Filament\Resources\BankApplications\Tables;

use App\Filament\Resources\BankApplications\BankApplicationResource;
use App\Models\Bank;
use App\Models\BankApplication;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (BankApplication $record): ?string => BankApplicationResource::getUrl('view', ['record' => $record]))
            ->searchable(BankApplication::query()->exists())
            ->searchPlaceholder('Buscar por código, produto ou conta...')
            ->searchDebounce('400ms')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma aplicação corresponde aos filtros selecionados'
                : 'Nenhuma aplicação bancária cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as aplicações cadastradas.'
                : 'Cadastre os produtos bancários de cada conta para vinculá-los aos fundos das operações.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeira aplicação')
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
                TextColumn::make('bankAccount.bank.name')
                    ->label('Banco')
                    ->weight('semibold')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('bankAccount.display_account')
                    ->label('Conta')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('codigo_produto')
                    ->label('Código do produto')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nome_produto')
                    ->label('Produto')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(1)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (BankApplication $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (BankApplication $record): string => $record->status === BankApplication::STATUS_ACTIVE ? 'success' : 'gray'),

                TextColumn::make('funds_count')
                    ->label('Fundos')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),

                TextColumn::make('shared')
                    ->label('Uso')
                    ->state(fn (BankApplication $record): string => $record->distinctEmissionCount() > 1 ? 'Compartilhado' : '—')
                    ->badge(fn (BankApplication $record): bool => $record->distinctEmissionCount() > 1)
                    ->color('warning')
                    ->tooltip(fn (BankApplication $record): ?string => $record->distinctEmissionCount() > 1
                        ? 'Esta aplicação física atende a fundos de mais de uma operação.'
                        : null),
            ])
            ->filters([
                SelectFilter::make('bank')
                    ->label('Banco')
                    ->options(fn (): array => Bank::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $bankId): Builder => $query->whereHas(
                            'bankAccount',
                            fn (Builder $accountQuery): Builder => $accountQuery->where('bank_id', $bankId),
                        ),
                    )),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(BankApplication::STATUS_OPTIONS),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-m-eye'),
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-m-pencil-square'),
                    DeleteAction::make()
                        ->label('Excluir')
                        ->visible(fn (BankApplication $record): bool => BankApplicationResource::canDelete($record)),
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
