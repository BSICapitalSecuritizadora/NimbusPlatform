<?php

namespace App\Filament\Resources\Measurements\Tables;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Services\MeasurementWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MeasurementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Measurement $record): ?string => MeasurementResource::canView($record)
                ? MeasurementResource::getUrl('view', ['record' => $record])
                : (MeasurementResource::canEdit($record) ? MeasurementResource::getUrl('edit', ['record' => $record]) : null))
            ->searchable(Measurement::query()->exists())
            ->searchPlaceholder('Buscar por operação ou empreendimento...')
            ->searchDebounce('400ms')
            ->defaultSort('uploaded_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma medição corresponde aos filtros selecionados'
                : 'Nenhuma medição cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as medições cadastradas.'
                : 'Cadastre a primeira medição para acompanhar o avanço e a documentação das operações de obra.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeira medição')
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
                TextColumn::make('operation.title')
                    ->label('Operação')
                    ->description(fn (Measurement $record): ?string => $record->operation?->code)
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Measurement $record): ?string => $record->operation?->title)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('developments')
                    ->label('Empreendimentos')
                    ->state(function (Measurement $record): string {
                        $names = static::developmentNames($record);

                        if ($names->isEmpty()) {
                            return '—';
                        }

                        if ($names->count() === 1) {
                            return $names->first();
                        }

                        return $names->first().' +'.($names->count() - 1);
                    })
                    ->tooltip(function (Measurement $record): ?string {
                        $names = static::developmentNames($record);

                        return $names->count() > 1 ? $names->implode(', ') : null;
                    })
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'assets.planSet.construction',
                        fn (Builder $constructionQuery): Builder => $constructionQuery->where('development_name', 'like', "%{$search}%"),
                    ))
                    ->toggleable(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('assets_count')
                    ->label('Arquivos')
                    ->counts('assets')
                    ->icon('heroicon-m-paper-clip')
                    ->formatStateUsing(fn (int $state): string => $state === 1 ? '1 arquivo' : "{$state} arquivos")
                    ->toggleable(),

                TextColumn::make('current_stage')
                    ->label('Etapa')
                    ->badge()
                    ->state(fn (Measurement $record): string => MeasurementWorkflow::STAGE_LABELS[app(MeasurementWorkflow::class)->unifiedStage($record)] ?? '—')
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Measurement::STATUS_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'finalized', 'approved' => 'success',
                        'rejected' => 'danger',
                        'paused' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('uploaded_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('Ainda não enviada')
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters(
                Measurement::query()->exists()
                    ? [
                        SelectFilter::make('status')
                            ->label('Situação')
                            ->options(Measurement::STATUS_OPTIONS),

                        SelectFilter::make('operation_id')
                            ->label('Operação')
                            ->relationship('operation', 'title')
                            ->searchable()
                            ->preload(),
                    ]
                    : []
            )
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->color('info')
                    ->icon('heroicon-m-eye'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Nomes únicos dos empreendimentos vinculados à medição via arquivos/plano.
     *
     * @return Collection<int, string>
     */
    protected static function developmentNames(Measurement $record): Collection
    {
        return $record->assets
            ->map(fn ($asset) => $asset->planSet?->construction?->development_name)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma medição cadastrada" do "nenhuma corresponde aos filtros".
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
