<?php

namespace App\Filament\Resources\Operations\Tables;

use App\Enums\OperationStatus;
use App\Filament\Resources\Operations\OperationResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Operation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OperationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Operation $record): ?string => OperationResource::canView($record)
                ? OperationResource::getUrl('view', ['record' => $record])
                : (OperationResource::canEdit($record) ? OperationResource::getUrl('edit', ['record' => $record]) : null))
            ->searchable(static::visibleOperationsExist())
            ->searchPlaceholder('Buscar por código, título ou emissão...')
            ->searchDebounce('400ms')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma operação de obra corresponde aos filtros selecionados'
                : 'Nenhuma operação de obra cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as operações cadastradas.'
                : 'Cadastre a primeira operação para acompanhar valores, situação e próximas medições.')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeira operação')
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
                TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Operation $record): string => $record->title)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Operation $record): ?string => $record->emission?->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('developments')
                    ->label('Empreendimentos')
                    ->state(function (Operation $record): string {
                        $names = $record->planSets
                            ->map(fn ($ps) => $ps->construction?->development_name)
                            ->filter()
                            ->unique()
                            ->values();

                        if ($names->isEmpty() && $record->construction?->development_name) {
                            $names = collect([$record->construction->development_name]);
                        }

                        if ($names->isEmpty()) {
                            return '—';
                        }

                        if ($names->count() === 1) {
                            return $names->first();
                        }

                        return $names->first().' +'.($names->count() - 1);
                    })
                    ->tooltip(function (Operation $record): ?string {
                        $names = $record->planSets
                            ->map(fn ($ps) => $ps->construction?->development_name)
                            ->filter()
                            ->unique()
                            ->values();

                        if ($names->isEmpty() && $record->construction?->development_name) {
                            $names = collect([$record->construction->development_name]);
                        }

                        return $names->count() > 1 ? $names->implode(', ') : null;
                    })
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (OperationStatus $state): string => $state->label())
                    ->color(fn (OperationStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Valor Total')
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('semibold')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('next_pending_measurement_at')
                    ->label('Próxima Medição')
                    ->date('m/Y')
                    ->placeholder('Sem medição prevista')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters(
                static::visibleOperationsExist()
                    ? [
                        SelectFilter::make('status')
                            ->label('Situação')
                            ->options(OperationStatus::options()),

                        SelectFilter::make('emission_id')
                            ->label('Emissão')
                            ->relationship(
                                'emission',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => auth()->user() === null
                                    ? $query->whereRaw('1 = 0')
                                    : $query->whereIn(
                                        'id',
                                        Operation::query()->visibleTo(auth()->user())->select('emission_id'),
                                    ),
                            )
                            ->searchable()
                            ->preload()
                            ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
                    ]
                    : []
            )
            // Exclusão física saiu da interface: uma operação apagada levava
            // consigo, em cascata, medições, arquivos, pausas, alertas de SLA e
            // pagamentos. Operação criada por engano se cancela com motivo, e o
            // registro permanece auditável. As proteções de banco e de modelo
            // continuam onde estavam -- o botão saiu, a defesa não.
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->color('info')
                    ->icon('heroicon-m-eye'),

                ActionGroup::make([
                    EditAction::make(),
                    OperationResource::getActivateOperationAction(),
                    OperationResource::getCompleteOperationAction(),
                    OperationResource::getCancelOperationAction(),
                    OperationResource::getReopenOperationAction(),
                ]),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma operação cadastrada" do "nenhuma corresponde aos filtros".
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

    protected static function visibleOperationsExist(): bool
    {
        $user = auth()->user();

        return $user !== null && Operation::query()->visibleTo($user)->exists();
    }
}
