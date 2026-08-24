<?php

namespace App\Filament\Resources\Negotiations\Tables;

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Models\Negotiation;
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

class NegotiationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Negotiation $record): ?string => NegotiationResource::canView($record)
                ? NegotiationResource::getUrl('view', ['record' => $record])
                : null)
            ->searchable(Negotiation::query()->exists())
            ->searchPlaceholder('Buscar por emissão ou empreendimento...')
            ->defaultSort('reference_month', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma negociação corresponde aos filtros selecionados'
                : 'Nenhuma negociação cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as negociações cadastradas.'
                : 'Cadastre a primeira negociação para iniciar o acompanhamento de vendas e distratos dos empreendimentos.')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Cadastrar negociação')
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
            ->filters(
                Negotiation::query()->exists()
                    ? [
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
                            ->options(fn (): array => Negotiation::query()
                                ->orderByDesc('reference_month')
                                ->pluck('reference_month')
                                ->filter()
                                ->unique()
                                ->mapWithKeys(fn (mixed $referenceMonth): array => [
                                    (string) Negotiation::normalizeReferenceMonth($referenceMonth) => Negotiation::formatReferenceMonthForDisplay($referenceMonth),
                                ])
                                ->all()),
                    ]
                    : [],
            )
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (Negotiation $record): bool => NegotiationResource::canView($record)),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Negotiation $record): bool => NegotiationResource::canEdit($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da negociação'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
}
