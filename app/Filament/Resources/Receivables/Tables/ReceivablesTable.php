<?php

namespace App\Filament\Resources\Receivables\Tables;

use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Receivable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reference_month')
                    ->label('Mês')
                    ->date('m/Y')
                    ->sortable(),

                ColumnGroup::make('Carteira')
                    ->columns([
                        TextColumn::make('portfolio_id')
                            ->label('Carteira')
                            ->sortable(),

                        TextColumn::make('active_contracts_count')
                            ->label('Contratos ativos')
                            ->alignEnd()
                            ->sortable(),
                    ]),

                ColumnGroup::make('Fluxo financeiro')
                    ->columns([
                        TextColumn::make('expected_amortization_amount')
                            ->label('Amortização esperada')
                            ->money('BRL')
                            ->alignEnd()
                            ->sortable()
                            ->toggleable(),

                        TextColumn::make('expected_interest_amount')
                            ->label('Juros esperado')
                            ->money('BRL')
                            ->alignEnd()
                            ->sortable()
                            ->toggleable(isToggledHiddenByDefault: true),

                        TextColumn::make('total_prepayment_amount')
                            ->label('Pré-pagamento')
                            ->money('BRL')
                            ->alignEnd()
                            ->sortable(),

                        TextColumn::make('total_outstanding_balance_amount')
                            ->label('Saldo devedor total')
                            ->money('BRL')
                            ->alignEnd()
                            ->sortable(),
                    ]),

                ColumnGroup::make('Risco')
                    ->columns([
                        TextColumn::make('monthly_default_balance_amount')
                            ->label('Inadimplência no mês')
                            ->money('BRL')
                            ->alignEnd()
                            ->sortable(),

                        TextColumn::make('total_default_balance_amount')
                            ->label('Inadimplência geral')
                            ->money('BRL')
                            ->alignEnd()
                            ->sortable(),

                        TextColumn::make('sale_ltv_ratio')
                            ->label('LTV de venda')
                            ->formatStateUsing(fn (mixed $state): string => $state === null ? '-' : number_format(((float) $state) * 100, 2, ',', '.').'%')
                            ->alignEnd()
                            ->sortable(),
                    ]),

                ColumnGroup::make('Indicadores')
                    ->columns([
                        TextColumn::make('portfolio_duration_months')
                            ->label('Duration (meses)')
                            ->numeric(decimalPlaces: 2)
                            ->alignEnd()
                            ->sortable(),

                        TextColumn::make('average_rate_details')
                            ->label('Taxa média')
                            ->wrap()
                            ->toggleable(isToggledHiddenByDefault: true),
                    ]),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                SelectFilter::make('reference_month')
                    ->label('Mês')
                    ->options(fn (): array => Receivable::query()
                        ->orderByDesc('reference_month')
                        ->pluck('reference_month')
                        ->filter()
                        ->unique()
                        ->mapWithKeys(fn (mixed $referenceMonth): array => [
                            (string) Receivable::normalizeReferenceMonth($referenceMonth) => Receivable::formatReferenceMonthForDisplay($referenceMonth),
                        ])
                        ->all()),

                SelectFilter::make('portfolio_id')
                    ->label('Carteira')
                    ->options(fn (): array => Receivable::query()
                        ->orderBy('portfolio_id')
                        ->pluck('portfolio_id', 'portfolio_id')
                        ->all()),
            ])
            ->defaultSort('reference_month', 'desc')
            ->searchPlaceholder('Buscar por emissão...')
            ->stackedOnMobile()
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(fn (ListReceivables $livewire): string => self::hasActiveSearchOrFilters($livewire)
                ? 'Nenhum recebível encontrado com os filtros atuais'
                : 'Nenhum resumo de recebíveis cadastrado')
            ->emptyStateDescription(fn (ListReceivables $livewire): string => self::hasActiveSearchOrFilters($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar outras competências.'
                : 'Cadastre manualmente ou importe uma planilha para iniciar o acompanhamento da carteira.')
            ->emptyStateActions([
                Action::make('createReceivable')
                    ->label('Cadastrar resumo')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => ReceivableResource::getUrl('create'))
                    ->visible(fn (ListReceivables $livewire): bool => ReceivableResource::canCreate() && (! self::hasActiveSearchOrFilters($livewire))),
                Action::make('importReceivables')
                    ->label('Importar planilha')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->action(fn (ListReceivables $livewire) => $livewire->mountAction('import'))
                    ->visible(fn (ListReceivables $livewire): bool => ! self::hasActiveSearchOrFilters($livewire)),
                Action::make('clearTableFilters')
                    ->label('Limpar filtros')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->action(function (ListReceivables $livewire): void {
                        $livewire->resetTableFiltersForm();
                        $livewire->resetTableSearch();
                    })
                    ->visible(fn (ListReceivables $livewire): bool => self::hasActiveSearchOrFilters($livewire)),
            ]);
    }

    protected static function hasActiveSearchOrFilters(ListReceivables $livewire): bool
    {
        if (filled($livewire->tableSearch)) {
            return true;
        }

        return collect($livewire->tableFilters ?? [])
            ->contains(fn (mixed $state): bool => filled($state['value'] ?? null)
                || collect((array) ($state['values'] ?? []))->filter()->isNotEmpty());
    }
}
