<?php

namespace App\Filament\Resources\Constructions\Tables;

use App\Filament\Resources\Constructions\ConstructionResource;
use App\Models\Construction;
use App\Models\ExpenseServiceProvider;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConstructionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Construction $record): ?string => ConstructionResource::canEdit($record)
                ? ConstructionResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(Construction::query()->exists())
            ->searchPlaceholder('Buscar por emissão, empreendimento, cidade ou empresa...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma obra corresponde aos filtros selecionados'
                : 'Nenhuma obra cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as obras cadastradas.'
                : 'Cadastre a primeira obra para acompanhar cronograma, localização e valor previsto.')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Cadastrar obra')
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

                TextColumn::make('development_name')
                    ->label('Empreendimento')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Construction $record): string => $record->development_name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('city')
                    ->label('Localização')
                    ->formatStateUsing(fn (?string $state, Construction $record): string => match (true) {
                        filled($state) && filled($record->state) => $state.' · '.$record->state,
                        filled($state) => $state,
                        filled($record->state) => $record->state,
                        default => '—',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('construction_start_date')
                    ->label('Cronograma')
                    ->formatStateUsing(fn (mixed $state, Construction $record): string => static::formatSchedule($record))
                    ->sortable(),

                TextColumn::make('estimated_value')
                    ->label('Valor previsto')
                    ->money('BRL')
                    ->weight('semibold')
                    ->alignEnd()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('measurementCompany.name')
                    ->label('Empresa de medição')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Construction $record): ?string => $record->measurementCompany?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('development_cnpj')
                    ->label('CNPJ do empreendimento')
                    ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                    ->color('gray')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('state')
                    ->label('Estado')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('construction_end_date')
                    ->label('Conclusão')
                    ->date('m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('measurementCompany.cnpj')
                    ->label('CNPJ da empresa de medição')
                    ->formatStateUsing(fn (?string $state): string => ExpenseServiceProvider::formatCnpj($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters(
                Construction::query()->exists()
                    ? [
                        SelectFilter::make('emission_id')
                            ->label('Emissão')
                            ->relationship('emission', 'name')
                            ->searchable()
                            ->preload(),

                        SelectFilter::make('development_name')
                            ->label('Empreendimento')
                            ->options(fn (): array => Construction::query()
                                ->orderBy('development_name')
                                ->pluck('development_name', 'development_name')
                                ->all())
                            ->searchable()
                            ->preload(),

                        SelectFilter::make('measurement_company_id')
                            ->label('Empresa de medição')
                            ->relationship(
                                'measurementCompany',
                                'name',
                                fn (Builder $query): Builder => $query
                                    ->whereHas('type', fn (Builder $query): Builder => $query->where('name', Construction::MEASUREMENT_COMPANY_TYPE_NAME)),
                            )
                            ->searchable()
                            ->preload(),

                        SelectFilter::make('state')
                            ->label('Estado')
                            ->options(Construction::STATE_OPTIONS),
                    ]
                    : [],
            )
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
     * Formata o cronograma como "início → conclusão", com "—" para ausências.
     */
    protected static function formatSchedule(Construction $record): string
    {
        $start = $record->construction_start_date?->format('m/Y');
        $end = $record->construction_end_date?->format('m/Y');

        if ($start === null && $end === null) {
            return '—';
        }

        return ($start ?? '—').' → '.($end ?? '—');
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma obra cadastrada" do "nenhuma corresponde aos filtros".
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
