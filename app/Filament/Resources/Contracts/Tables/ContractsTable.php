<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Client;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\Emission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ContractsTable
{
    /**
     * How many clients the filter picker returns per search, so the query stays
     * bounded once the base holds thousands of buyers.
     */
    private const CLIENT_SEARCH_LIMIT = 50;

    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Contract $record): ?string => ContractResource::canView($record)
                ? ContractResource::getUrl('view', ['record' => $record])
                : null)
            ->searchPlaceholder('Buscar por contrato, comprador, CPF/CNPJ ou unidade...')
            ->defaultSort('sale_date', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('construction.emission.name')
                    ->label('Operação')
                    ->description(fn (Contract $record): ?string => $record->construction?->development_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereHas(
                        'construction.emission',
                        fn (Builder $emissionQuery): Builder => $emissionQuery->where('name', 'like', "%{$search}%"),
                    )->orWhereHas(
                        'construction',
                        fn (Builder $constructionQuery): Builder => $constructionQuery->where('development_name', 'like', "%{$search}%"),
                    ))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereHas(
                        'construction',
                        fn (Builder $constructionQuery): Builder => $constructionQuery->where('development_name', 'like', "%{$search}%"),
                    ))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('constructionUnit.unit')
                    ->label('Unidade')
                    ->description(fn (Contract $record): ?string => filled($record->constructionUnit?->block)
                        ? 'Bloco '.$record->constructionUnit->block
                        : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereHas(
                        'constructionUnit',
                        fn (Builder $unitQuery): Builder => $unitQuery
                            ->where('unit', 'like', "%{$search}%")
                            ->orWhere('block', 'like', "%{$search}%"),
                    ))
                    ->sortable()
                    ->weight('bold'),

                /**
                 * Compact on purpose: a contract with four buyers must not make
                 * its row four lines tall. The names beyond the second are
                 * counted, and the full list is one click away on the contract.
                 */
                TextColumn::make('clients.name')
                    ->label('Compradores')
                    ->weight('semibold')
                    ->state(fn (Contract $record): string => $record->buyersLabel())
                    ->description(fn (Contract $record): ?string => $record->clients->count() > 1
                        ? $record->clients->count().' compradores'
                        : null)
                    ->tooltip(fn (Contract $record): ?string => $record->clients->pluck('name')->implode(', ') ?: null)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = Str::digitsOnly($search);

                        return $query->orWhereHas('clients', function (Builder $clientQuery) use ($search, $digits): void {
                            $clientQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('trade_name', 'like', "%{$search}%");

                            if ($digits !== '') {
                                $clientQuery->orWhere('document', 'like', "%{$digits}%");
                            }
                        });
                    }),

                TextColumn::make('code')
                    ->label('Contrato')
                    ->weight('medium')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('sale_date')
                    ->label('Data Venda')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('sale_value')
                    ->label('Valor')
                    ->formatStateUsing(fn (mixed $state): string => 'R$ '.MoneyFormatter::formatCurrencyForDisplay($state))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ContractStatus $state): string => $state->label())
                    ->color(fn (ContractStatus $state): string => $state->color())
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('cancellation_date')
                    ->label('Data Distrato')
                    ->date('d/m/Y')
                    ->placeholder('—')
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

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ContractStatus::options()),

                /**
                 * Through the relation: a buyer selected here matches every
                 * contract they are part of, not only the ones where they happen
                 * to be listed first.
                 */
                SelectFilter::make('buyer')
                    ->label('Comprador')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Client::query()
                        ->search($search)
                        ->orderBy('name')
                        ->limit(self::CLIENT_SEARCH_LIMIT)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Client::withTrashed()->find($value)?->name)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $clientId): Builder => $query->whereHas(
                            'clients',
                            fn (Builder $clientQuery): Builder => $clientQuery->whereKey($clientId),
                        ),
                    )),

                Filter::make('sale_date')
                    ->label('Data da Venda')
                    ->form([
                        DatePicker::make('sold_from')->label('Vendido de')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('sold_until')->label('Vendido até')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['sold_from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('sale_date', '>=', $date),
                        )
                        ->when(
                            $data['sold_until'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('sale_date', '<=', $date),
                        )),

                TrashedFilter::make()
                    ->label('Contratos excluídos'),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (Contract $record): bool => ContractResource::canView($record)),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Contract $record): bool => ContractResource::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->modalHeading('Excluir contrato')
                        ->modalDescription('O contrato deixa de aparecer na listagem e libera a unidade, mas é preservado para manter o histórico comercial.')
                        ->visible(fn (Contract $record): bool => ContractResource::canDelete($record)),

                    RestoreAction::make()
                        ->label('Restaurar')
                        // While the contract was away the unit may have been sold
                        // again. Restoring it would put two live contracts on the
                        // same unit, which the database refuses -- so it is
                        // stopped here, with an explanation instead of an error.
                        ->before(function (Contract $record, RestoreAction $action): void {
                            if (! $record->occupiesUnit()) {
                                return;
                            }

                            $occupying = Contract::occupyingContract($record->construction_unit_id, $record->getKey());

                            if ($occupying === null) {
                                return;
                            }

                            Notification::make()
                                ->danger()
                                ->title('Contrato não restaurado.')
                                ->body(sprintf(
                                    'Esta unidade já possui um contrato %s (%s). Registre o distrato dele antes de restaurar este contrato.',
                                    mb_strtolower($occupying->status->label()),
                                    $occupying->code,
                                ))
                                ->persistent()
                                ->send();

                            $action->halt();
                        })
                        ->visible(fn (Contract $record): bool => ContractResource::canRestore($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do contrato'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn ($livewire): string => self::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum contrato encontrado'
                : 'Nenhum contrato cadastrado')
            ->emptyStateDescription(fn ($livewire): string => self::hasActiveFiltersOrSearch($livewire)
                ? 'Tente ajustar ou limpar os filtros e termos de busca para localizar os contratos.'
                : 'Cadastre manualmente ou importe uma planilha para iniciar.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateActions([
                Action::make('createContractEmptyState')
                    ->label('Novo Contrato')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(fn (): string => ContractResource::getUrl('create'))
                    ->visible(fn ($livewire): bool => ! self::hasActiveFiltersOrSearch($livewire) && ContractResource::canCreate()),

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
