<?php

namespace App\Filament\Resources\BankAccounts\Tables;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
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

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (BankAccount $record): ?string => BankAccountResource::getUrl('view', ['record' => $record]))
            ->searchable(BankAccount::query()->exists())
            ->searchPlaceholder('Buscar por banco, agência ou conta...')
            ->searchDebounce('400ms')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma conta corresponde aos filtros selecionados'
                : 'Nenhuma conta bancária cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as contas cadastradas.'
                : 'Cadastre a primeira conta bancária global para vinculá-la a fundos de uma ou mais operações.')
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeira conta')
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
                TextColumn::make('bank.name')
                    ->label('Banco')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(1)
                    ->tooltip(fn (BankAccount $record): ?string => $record->bank?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('display_agency')
                    ->label('Agência')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('display_account')
                    ->label('Conta')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('holder_context')
                    ->label('Contexto do titular')
                    ->wrap()
                    ->lineClamp(1)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bank_applications_count')
                    ->label('Aplicações')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->sortable()
                    ->tooltip('Fundos vinculados diretamente a esta conta.'),

                TextColumn::make('conta_azul_account_id')
                    ->label('Conta Azul')
                    ->state(fn (BankAccount $record): string => filled($record->conta_azul_account_id) ? 'Configurado' : 'Pendente')
                    ->badge()
                    ->color(fn (BankAccount $record): string => filled($record->conta_azul_account_id) ? 'success' : 'gray')
                    ->tooltip('Configuração conceitual: a sincronização continua operando por fundo.'),
            ])
            ->filters([
                SelectFilter::make('bank_id')
                    ->label('Banco')
                    ->relationship('bank', 'name')
                    ->searchable()
                    ->preload(),
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
                        ->visible(fn (BankAccount $record): bool => BankAccountResource::canDelete($record)),
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
