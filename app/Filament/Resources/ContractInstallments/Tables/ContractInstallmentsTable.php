<?php

namespace App\Filament\Resources\ContractInstallments\Tables;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractInstallmentStatus;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Client;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractInstallmentsTable
{
    /**
     * How many records the contract and client pickers return per search, so the
     * queries stay bounded once the base holds thousands of each.
     */
    private const SEARCH_LIMIT = 50;

    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ContractInstallment $record): ?string => ContractResource::canView($record->contract)
                ? ContractResource::getUrl('view', ['record' => $record->contract_id])
                : null)
            ->searchPlaceholder('Buscar por parcela, contrato, cliente, CPF/CNPJ ou unidade...')
            ->defaultSort('due_date', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('contract.construction.emission.name')
                    ->label('Operação')
                    ->description(fn (ContractInstallment $record): ?string => $record->contract?->construction?->development_name)
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('contract.construction.development_name')
                    ->label('Empreendimento')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contract.code')
                    ->label('Contrato')
                    ->weight('semibold')
                    ->description(fn (ContractInstallment $record): ?string => $record->contract?->constructionUnit?->display_name)
                    ->sortable()
                    ->copyable(),

                TextColumn::make('contract.clients.name')
                    ->label('Compradores')
                    ->weight('medium')
                    ->state(fn (ContractInstallment $record): string => $record->contract?->buyersLabel() ?? '—')
                    ->tooltip(fn (ContractInstallment $record): ?string => $record->contract?->clients->pluck('name')->implode(', ') ?: null)
                    ->toggleable(),

                TextColumn::make('number')
                    ->label('Nº')
                    ->weight('bold')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->color(fn (ContractInstallment $record): string => $record->status === ContractInstallmentStatus::Overdue ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('expected_value')
                    ->label('Previsto')
                    ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('paid_value')
                    ->label('Pago')
                    ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
                    ->placeholder('R$ 0,00')
                    ->alignEnd()
                    ->sortable(),

                self::outstandingColumn(),

                self::statusColumn(),

                TextColumn::make('days_overdue')
                    ->label('Dias em Atraso')
                    ->state(fn (ContractInstallment $record): int => $record->days_overdue)
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : '—')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment_date')
                    ->label('Data Pagamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancellation_date')
                    ->label('Data Cancelamento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color('danger')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Medium)
            ->filtersFormMaxHeight('420px')
            ->filters(self::filters())
            ->actions([
                ActionGroup::make(self::rowActions())
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da parcela'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn ($livewire): string => self::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma parcela encontrada'
                : 'Nenhuma parcela cadastrada')
            ->emptyStateDescription(fn ($livewire): string => self::hasActiveFiltersOrSearch($livewire)
                ? 'Tente ajustar ou limpar os filtros e termos de busca para localizar as parcelas.'
                : 'Cadastre manualmente ou importe uma planilha para começar o acompanhamento financeiro.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateActions([
                Action::make('createInstallmentEmptyState')
                    ->label('Nova Parcela')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(fn (): string => ContractInstallmentResource::getUrl('create'))
                    ->visible(fn ($livewire): bool => ! self::hasActiveFiltersOrSearch($livewire) && ContractInstallmentResource::canCreate()),

                Action::make('clearTableFilters')
                    ->label('Limpar filtros')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->action(function ($livewire): void {
                        $livewire->tableSearch = '';
                        $livewire->resetTableFiltersForm();
                    })
                    ->visible(fn ($livewire): bool => self::hasActiveFiltersOrSearch($livewire)),
            ]);
    }

    /**
     * Derived in PHP for display and in SQL for sorting, from the same formula.
     * The direction is taken from the two literals Filament can produce, never
     * interpolated from the request.
     */
    private static function outstandingColumn(): TextColumn
    {
        return TextColumn::make('outstanding_value')
            ->label('Saldo')
            ->state(fn (ContractInstallment $record): float => $record->outstanding_value)
            ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
            ->color(fn (mixed $state): string => ((float) $state) > 0 ? 'warning' : 'gray')
            ->alignEnd()
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                'CASE WHEN expected_value - COALESCE(paid_value, 0) < 0 THEN 0 ELSE expected_value - COALESCE(paid_value, 0) END '
                    .($direction === 'desc' ? 'desc' : 'asc'),
            ));
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Status')
            ->badge()
            ->alignCenter()
            ->state(fn (ContractInstallment $record): ContractInstallmentStatus => $record->status)
            ->formatStateUsing(fn (ContractInstallmentStatus $state): string => $state->label())
            ->color(fn (ContractInstallmentStatus $state): string => $state->color());
    }

    /**
     * @return list<mixed>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('emission')
                ->label('Emissão')
                ->options(fn (): array => Emission::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    fn (Builder $query, mixed $emissionId): Builder => $query->forEmission($emissionId),
                )),

            SelectFilter::make('construction')
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
                ->preload()
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    fn (Builder $query, mixed $constructionId): Builder => $query->whereHas(
                        'contract',
                        fn (Builder $contractQuery): Builder => $contractQuery->where('construction_id', $constructionId),
                    ),
                )),

            SelectFilter::make('contract_id')
                ->label('Contrato')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Contract::query()
                    ->search($search)
                    ->orderBy('code')
                    ->limit(self::SEARCH_LIMIT)
                    ->pluck('code', 'id')
                    ->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => Contract::withTrashed()->find($value)?->code),

            SelectFilter::make('client')
                ->label('Comprador')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Client::query()
                    ->search($search)
                    ->orderBy('name')
                    ->limit(self::SEARCH_LIMIT)
                    ->pluck('name', 'id')
                    ->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => Client::withTrashed()->find($value)?->name)
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    /**
                     * Through the buyer set of the contract: a buyer reaches the
                     * installments of every contract they are part of, not only
                     * the ones where they happen to be listed first.
                     */
                    fn (Builder $query, mixed $clientId): Builder => $query->whereHas(
                        'contract',
                        fn (Builder $contractQuery): Builder => $contractQuery->whereHas(
                            'clients',
                            fn (Builder $clientQuery): Builder => $clientQuery->whereKey($clientId),
                        ),
                    ),
                )),

            /**
             * The status is derived, so the filter applies the same condition the
             * accessor computes instead of comparing a column that does not
             * exist. Both live in the model, side by side, so they cannot drift.
             */
            SelectFilter::make('status')
                ->label('Status')
                ->options(ContractInstallmentStatus::options())
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    ContractInstallmentStatus::tryFrom((string) ($data['value'] ?? '')),
                    fn (Builder $query, ContractInstallmentStatus $status): Builder => $query->withStatus($status),
                )),

            TernaryFilter::make('overdue')
                ->label('Somente em atraso')
                ->placeholder('Todas as parcelas')
                ->trueLabel('Apenas em atraso')
                ->falseLabel('Exceto em atraso')
                ->queries(
                    true: fn (Builder $query): Builder => $query->withStatus(ContractInstallmentStatus::Overdue),
                    false: fn (Builder $query): Builder => $query->whereNot(
                        fn (Builder $query): Builder => $query->withStatus(ContractInstallmentStatus::Overdue),
                    ),
                    blank: fn (Builder $query): Builder => $query,
                ),

            Filter::make('due_date')
                ->label('Vencimento')
                ->form([
                    DatePicker::make('due_from')->label('Vence de')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('due_until')->label('Vence até')->native(false)->displayFormat('d/m/Y'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(
                        $data['due_from'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->where('due_date', '>=', $date),
                    )
                    ->when(
                        $data['due_until'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->where('due_date', '<=', $date),
                    )),

            Filter::make('payment_date')
                ->label('Pagamento')
                ->form([
                    DatePicker::make('paid_from')->label('Pago de')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('paid_until')->label('Pago até')->native(false)->displayFormat('d/m/Y'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(
                        $data['paid_from'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->where('payment_date', '>=', $date),
                    )
                    ->when(
                        $data['paid_until'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->where('payment_date', '<=', $date),
                    )),

            TrashedFilter::make()
                ->label('Parcelas excluídas'),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function rowActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canEdit($record)),

            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir parcela')
                ->modalDescription('Use a exclusão apenas para um registro criado por engano. Para tirar uma parcela do fluxo contratual preservando o histórico, informe a data de cancelamento.')
                ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canDelete($record)),

            RestoreAction::make()
                ->label('Restaurar')
                ->visible(fn (ContractInstallment $record): bool => ContractInstallmentResource::canRestore($record)),
        ];
    }

    /**
     * Verifica se há busca ou filtros ativos na tabela para alternar o estado vazio.
     */
    protected static function hasActiveFiltersOrSearch(mixed $livewire): bool
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
