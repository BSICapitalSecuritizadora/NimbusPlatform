<?php

namespace App\Filament\Resources\Negotiations\Tables;

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Models\Emission;
use App\Models\Negotiation;
use App\Services\Reports\ContractNegotiationAggregates;
use App\Services\Reports\NegotiationGlobalProjection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;

class NegotiationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(function (Negotiation $record): ?string {
                $source = $record->getAttribute('source') ?? ($record->source ?? null);
                if ($source === 'contracts') {
                    // Synthetic ID — never route through NegotiationResource view/edit (would 404)
                    return null;
                }

                return NegotiationResource::canView($record)
                    ? NegotiationResource::getUrl('view', ['record' => $record])
                    : null;
            })
            ->recordAction(null)
            ->searchable(true)
            ->searchPlaceholder('Buscar por emissão ou empreendimento...')
            ->defaultSort('reference_month', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(function ($livewire): string {
                if (static::isContractsModeActive($livewire)) {
                    return static::hasActiveFiltersOrSearch($livewire)
                        ? 'Nenhuma negociação derivada de contratos corresponde aos filtros selecionados'
                        : 'Nenhuma venda ou distrato identificado.';
                }
                if (static::hasAnyDataGlobally()) {
                    return static::hasActiveFiltersOrSearch($livewire)
                        ? 'Nenhuma negociação corresponde aos filtros selecionados'
                        : 'Nenhuma negociação encontrada';
                }

                return static::hasActiveFiltersOrSearch($livewire)
                    ? 'Nenhuma negociação corresponde aos filtros selecionados'
                    : 'Nenhuma negociação cadastrada';
            })
            ->emptyStateDescription(function ($livewire): string {
                if (static::isContractsModeActive($livewire)) {
                    return static::hasActiveFiltersOrSearch($livewire)
                        ? 'Ajuste a busca ou limpe os filtros para visualizar as negociações derivadas de contratos.'
                        : 'As negociações automáticas são geradas a partir das datas de venda e distrato dos contratos cadastrados. Cadastre contratos para ver as negociações aqui. As emissões configuradas como automáticas são geradas a partir dos contratos cadastrados.';
                }
                if (static::hasAnyDataGlobally()) {
                    // When global data exists but filtered empty, keep filter hint
                    if (static::hasActiveFiltersOrSearch($livewire)) {
                        return 'Ajuste a busca ou limpe os filtros para visualizar as negociações.';
                    }
                }
                if (app(NegotiationGlobalProjection::class)->shouldUseProjection()) {
                    return static::hasActiveFiltersOrSearch($livewire)
                        ? 'Ajuste a busca ou limpe os filtros para visualizar as negociações derivadas de contratos.'
                        : 'As negociações automáticas são geradas a partir das datas de venda e distrato dos contratos cadastrados. Cadastre contratos para ver as negociações aqui. As emissões configuradas como automáticas são geradas a partir dos contratos cadastrados.';
                }

                return static::hasActiveFiltersOrSearch($livewire)
                    ? 'Ajuste a busca ou limpe os filtros para visualizar as negociações cadastradas.'
                    : 'Cadastre a primeira negociação para iniciar o acompanhamento de vendas e distratos dos empreendimentos.';
            })
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Cadastrar negociação')
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->visible(function ($livewire): bool {
                        if (static::hasActiveFiltersOrSearch($livewire)) {
                            return false;
                        }
                        // Do not show primary create when global data already exists via contracts projection
                        // and no legacy emission is available for manual entry
                        if (! NegotiationResource::canCreate()) {
                            return false;
                        }
                        // If projection exists and has contract data, hide primary create to emphasize automatic
                        if (app(NegotiationGlobalProjection::class)->shouldUseProjection()) {
                            $hasLegacy = Emission::query()
                                ->where(function ($q) {
                                    $q->where('negotiations_source', Emission::NEGOTIATIONS_SOURCE_LEGACY)
                                        ->orWhereNull('negotiations_source');
                                })->exists();
                            if (! $hasLegacy) {
                                return false;
                            }
                            // In mixed mode, still hide create on empty global with contracts data to avoid misleading
                            if (static::hasAnyDataGlobally()) {
                                return false;
                            }
                        }

                        return ! static::isContractsModeActive($livewire);
                    }),

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
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->weight('semibold')
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('sales')
                    ->label('Vendas')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('cancellations')
                    ->label('Distratos')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('construction_id')
                    ->label('Empreendimento')
                    ->relationship('construction', 'development_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reference_month')
                    ->label('Competência')
                    ->options(function (): array {
                        // Merge legacy and contract-derived months for global filter
                        $legacyMonths = Negotiation::query()->orderByDesc('reference_month')->pluck('reference_month')->filter();
                        $contractMonths = app(ContractNegotiationAggregates::class)->aggregatedQuery([])->pluck('reference_month');

                        return collect($legacyMonths)->merge($contractMonths)
                            ->filter()->unique()
                            ->mapWithKeys(fn (mixed $referenceMonth): array => [
                                (string) Negotiation::normalizeReferenceMonth($referenceMonth) => Negotiation::formatReferenceMonthForDisplay($referenceMonth),
                            ])
                            ->sortDesc()
                            ->all();
                    }),

                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(['Venda' => 'Venda', 'Distrato' => 'Distrato'])
                    ->native(false),

            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(function (Negotiation $record): bool {
                            $source = $record->getAttribute('source') ?? null;
                            if ($source === 'contracts') {
                                return false;
                            }

                            return NegotiationResource::canView($record);
                        }),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(function (Negotiation $record): bool {
                            $source = $record->getAttribute('source') ?? null;
                            if ($source === 'contracts') {
                                return false;
                            }

                            return NegotiationResource::canEdit($record);
                        }),

                    Action::make('verDetalhes')
                        ->label('Ver detalhes')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(function (Negotiation $record): bool {
                            $source = $record->getAttribute('source') ?? null;

                            return $source === 'contracts';
                        })
                        ->slideOver()
                        ->modalHeading(function (Negotiation $record): string {
                            $month = $record->reference_month instanceof CarbonInterface
                                ? Carbon::parse($record->reference_month)->format('m/Y')
                                : Negotiation::formatReferenceMonthForDisplay($record->reference_month);

                            return "Negociações de {$month}";
                        })
                        ->modalDescription(function (Negotiation $record): string {
                            $emission = $record->emission?->name ?? $record->getAttribute('emission_name') ?? '—';
                            $dev = $record->construction?->development_name ?? $record->getAttribute('development_name') ?? '—';

                            return "{$emission} · {$dev}";
                        })
                        ->modalWidth(Width::FiveExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Fechar')
                        ->modalContent(function (Negotiation $record): View {
                            $emissionId = (int) ($record->getAttribute('emission_id') ?? $record->emission_id);
                            $constructionId = (int) ($record->getAttribute('construction_id') ?? $record->construction_id);
                            $referenceMonthRaw = $record->getAttribute('reference_month') ?? $record->reference_month;
                            $referenceMonth = $referenceMonthRaw instanceof CarbonInterface
                                ? $referenceMonthRaw->format('Y-m-d')
                                : (string) $referenceMonthRaw;

                            $events = app(ContractNegotiationAggregates::class)
                                ->detailEvents($emissionId, $constructionId, $referenceMonth);

                            return view('filament.negotiations.contract-details', [
                                'record' => $record,
                                'events' => $events,
                            ]);
                        }),

                    Action::make('verContratosLegacy')
                        ->label('Ver contratos')
                        ->icon('heroicon-o-document-text')
                        ->color('gray')
                        ->visible(fn (): bool => false),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da negociação'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(function (): bool {
                            // Bulk delete only for legacy rows
                            return ! app(NegotiationGlobalProjection::class)->shouldUseProjection();
                        }),
                ]),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma negociação cadastrada" do "nenhuma corresponde aos filtros".
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

    public static function hasAnyDataGlobally(): bool
    {
        return app(NegotiationGlobalProjection::class)->hasAnyData();
    }

    /**
     * Detect contracts-mode: emission filter selects an emission with negotiations_source = contracts.
     * For global emptyState we now also consider hasAnyDataGlobally() so mixed sources are handled.
     */
    protected static function isContractsModeActive($livewire): bool
    {
        $filters = $livewire->tableFilters ?? [];

        $emissionValue = null;

        if (isset($filters['emission_id'])) {
            $state = $filters['emission_id'];
            if (is_array($state)) {
                $emissionValue = $state['value'] ?? $state['values'][0] ?? null;
                if (is_array($emissionValue) && isset($emissionValue['value'])) {
                    $emissionValue = $emissionValue['value'];
                }
            } else {
                $emissionValue = $state;
            }
        }

        // Also check request fallback
        if (blank($emissionValue)) {
            $emissionValue = request()->input('tableFilters.emission_id.value')
                ?? request()->input('tableFilters.emission_id');
        }

        if (filled($emissionValue)) {
            $emission = Emission::query()->find($emissionValue);
            if ($emission && $emission->usesContractNegotiations()) {
                return true;
            }

            return false;
        }

        // No specific emission filter: true if global projection has contracts data
        // This fixes the screenshot bug: page must show derived rows without needing a filter
        return app(NegotiationGlobalProjection::class)->shouldUseProjection()
            && app(NegotiationGlobalProjection::class)->hasAnyData();
    }

    /**
     * Derived-data query for contracts mode: efficient grouped query (GROUP BY emission, construction, YM).
     * Uses indexed columns sale_date/cancellation_date and construction_id.
     */
    public static function contractAggregatesQuery(array $filters = []): QueryBuilder
    {
        return app(ContractNegotiationAggregates::class)->aggregatedQuery($filters);
    }

    /**
     * Alternative contract-derived table configuration (read-only aggregated view).
     * Can be used by ListNegotiations when emission is contracts-mode.
     */
    public static function configureForContracts(Table $table, array $filters = []): Table
    {
        // For contracts mode the underlying data is derived from contracts; we reuse the same columns
        // but disable editing actions that would mutate manual negotiations.
        return static::configure($table)
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (Negotiation $record): bool => NegotiationResource::canView($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da negociação'),
            ])
            ->toolbarActions([]);
    }
}
