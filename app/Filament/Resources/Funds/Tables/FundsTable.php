<?php

namespace App\Filament\Resources\Funds\Tables;

use App\Filament\Resources\Funds\FundResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Fund;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Fund $record): ?string => FundResource::canEdit($record)
                ? FundResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(Fund::query()->exists())
            ->searchPlaceholder('Buscar por operação, fundo ou banco...')
            ->searchDebounce('400ms')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum fundo corresponde aos filtros selecionados'
                : 'Nenhum fundo cadastrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar os fundos cadastrados.'
                : 'Cadastre o primeiro fundo para acompanhar aplicações, contas e saldos operacionais.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeiro fundo')
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
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Fund $record): ?string => $record->emission?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fundName.name')
                    ->label('Nome do Fundo')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Fund $record): ?string => $record->fundName?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fundType.name')
                    ->label('Tipo de Fundo')
                    ->wrap()
                    ->lineClamp(1)
                    ->tooltip(fn (Fund $record): ?string => $record->fundType?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('trade_name')
                    ->label('Nome Fantasia')
                    ->wrap()
                    ->lineClamp(1)
                    ->tooltip(fn (Fund $record): ?string => $record->trade_name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('fundApplication.name')
                    ->label('Aplicação')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Fund $record): ?string => $record->fundApplication?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('bank.name')
                    ->label('Banco')
                    ->wrap()
                    ->lineClamp(1)
                    ->tooltip(fn (Fund $record): ?string => $record->bank?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('agency')
                    ->label('Agência')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('account')
                    ->label('Conta Corrente')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->placeholder('R$ 0,00')
                    ->sortable(),

                TextColumn::make('minimum_balance')
                    ->label('Valor Mínimo')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('balance_status')
                    ->label('Status do Saldo')
                    ->state(fn (Fund $record): string => $record->requiresMonthlyBalanceUpdate() ? 'Atualização pendente' : 'Em dia')
                    ->badge()
                    ->color(fn (Fund $record): string => $record->requiresMonthlyBalanceUpdate() ? 'warning' : 'success'),

                TextColumn::make('balance_updated_at')
                    ->label('Saldo Atualizado Em')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Operação')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
                SelectFilter::make('fund_type_id')
                    ->label('Tipo de Fundo')
                    ->relationship('fundType', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
                SelectFilter::make('fund_application_id')
                    ->label('Aplicação')
                    ->relationship('fundApplication', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
                SelectFilter::make('bank_id')
                    ->label('Banco')
                    ->relationship('bank', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('account')
                    ->label('Conta Corrente')
                    ->options(fn (): array => Fund::query()
                        ->whereNotNull('account')
                        ->orderBy('account')
                        ->pluck('account', 'account')
                        ->all())
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
