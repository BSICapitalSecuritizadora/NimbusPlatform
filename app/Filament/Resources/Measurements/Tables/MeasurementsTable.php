<?php

namespace App\Filament\Resources\Measurements\Tables;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Measurement;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MeasurementsTable
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, mixed>>
     */
    public static function cockpitFiltersToTableState(array $filters): array
    {
        $tableFilters = [];

        if (filled($filters['competence_from'] ?? null) || filled($filters['competence_to'] ?? null)) {
            $tableFilters['competence_period'] = [
                'from' => $filters['competence_from'] ?? null,
                'to' => $filters['competence_to'] ?? null,
            ];
        }

        foreach (['operation_id', 'emission_id', 'responsible_user_id', 'stage', 'status', 'sla_status', 'assignment'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $tableFilters[$filter] = ['value' => $filters[$filter]];
            }
        }

        return $tableFilters;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Measurement $record): ?string => MeasurementResource::canView($record)
                ? MeasurementResource::getUrl('view', ['record' => $record])
                : (MeasurementResource::canEdit($record) ? MeasurementResource::getUrl('edit', ['record' => $record]) : null))
            ->searchable(static::visibleMeasurementsExist())
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
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::readModel()
                        ->applySearch($query, $search))
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

                TextColumn::make('sla')
                    ->label('SLA')
                    ->badge()
                    ->state(fn (Measurement $record): string => static::readModel()->slaLabel($record))
                    ->description(fn (Measurement $record): ?string => static::readModel()->slaDescription($record))
                    ->color(fn (Measurement $record): string => static::readModel()->slaColor($record))
                    ->toggleable(),

                TextColumn::make('uploaded_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('Ainda não enviada')
                    ->sortable(),
            ])
            ->filtersFormWidth(Width::ExtraLarge)
            ->filtersFormMaxHeight('680px')
            ->filters(
                static::visibleMeasurementsExist()
                    ? [
                        Filter::make('competence_period')
                            ->label('Competência')
                            ->schema([
                                DatePicker::make('from')->label('Competência inicial')->native(false),
                                DatePicker::make('to')->label('Competência final')->native(false),
                            ])
                            ->columns(2)
                            ->query(fn (Builder $query, array $data): Builder => $query
                                ->when(filled($data['from'] ?? null), fn (Builder $measurements): Builder => $measurements
                                    ->whereDate('reference_month', '>=', static::filterDate($data['from'])))
                                ->when(filled($data['to'] ?? null), fn (Builder $measurements): Builder => $measurements
                                    ->whereDate('reference_month', '<=', static::filterDate($data['to'])))),

                        SelectFilter::make('status')
                            ->label('Situação')
                            ->options(Measurement::STATUS_OPTIONS),

                        SelectFilter::make('operation_id')
                            ->label('Operação')
                            ->options(fn (): array => static::viewerOptions('operationOptions'))
                            ->searchable()
                            ->query(fn (Builder $query, array $data): Builder => $query
                                ->when(filled($data['value'] ?? null), fn (Builder $measurements): Builder => $measurements
                                    ->where('operation_id', (int) $data['value'])))
                            ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                        SelectFilter::make('emission_id')
                            ->label('Emissão')
                            ->options(fn (): array => static::viewerOptions('emissionOptions'))
                            ->searchable()
                            ->query(fn (Builder $query, array $data): Builder => $query
                                ->when(filled($data['value'] ?? null), fn (Builder $measurements): Builder => $measurements
                                    ->whereHas('operation', fn (Builder $operations): Builder => $operations
                                        ->where('emission_id', (int) $data['value']))))
                            ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                        SelectFilter::make('responsible_user_id')
                            ->label('Responsável')
                            ->options(fn (): array => static::viewerOptions('responsibleOptions'))
                            ->searchable()
                            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                                ? static::readModel()->applyResponsibleFilter($query, (int) $data['value'])
                                : $query),

                        SelectFilter::make('stage')
                            ->label('Etapa')
                            ->options(MeasurementWorkflow::STAGE_LABELS)
                            ->query(fn (Builder $query, array $data): Builder => $query
                                ->when(filled($data['value'] ?? null), fn (Builder $measurements): Builder => $measurements
                                    ->where('current_stage', (int) $data['value']))),

                        SelectFilter::make('sla_status')
                            ->label('SLA')
                            ->options([
                                MeasurementSlaService::STATUS_ON_TIME => 'No prazo',
                                MeasurementSlaService::STATUS_APPROACHING => 'Em atenção',
                                MeasurementSlaService::STATUS_OVERDUE => 'Vencido',
                                MeasurementSlaService::STATUS_PAUSED => 'Pausado',
                                MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => 'Calendário indisponível',
                            ])
                            ->query(fn (Builder $query, array $data): Builder => static::readModel()
                                ->applySlaFilter($query, $data['value'] ?? null)),

                        SelectFilter::make('assignment')
                            ->label('Atuação no escopo')
                            ->options([
                                'direct' => 'Participação direta',
                                'delegated' => 'Visível por delegação',
                            ])
                            ->query(function (Builder $query, array $data): Builder {
                                $viewer = auth()->user();

                                return $viewer instanceof User
                                    ? static::readModel()->applyAssignmentFilter($query, $viewer, $data['value'] ?? null)
                                    : $query->whereRaw('1 = 0');
                            }),
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

    protected static function visibleMeasurementsExist(): bool
    {
        $user = auth()->user();

        return $user !== null && Measurement::query()->visibleTo($user)->exists();
    }

    /** @return array<int, string> */
    protected static function viewerOptions(string $method): array
    {
        $viewer = auth()->user();

        return $viewer instanceof User
            ? static::readModel()->{$method}($viewer)
            : [];
    }

    protected static function readModel(): MeasurementOperationalReadModel
    {
        return once(fn (): MeasurementOperationalReadModel => app(MeasurementOperationalReadModel::class));
    }

    private static function filterDate(mixed $value): string
    {
        return Carbon::parse($value)->toDateString();
    }
}
