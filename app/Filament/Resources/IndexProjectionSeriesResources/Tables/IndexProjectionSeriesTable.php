<?php

namespace App\Filament\Resources\IndexProjectionSeriesResources\Tables;

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Services\IndexProjectionSeriesService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IndexProjectionSeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar séries projetadas...')
            ->searchDebounce('400ms')
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma série projetada encontrada'
                : 'Nenhuma série projetada cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Tente ajustar o termo pesquisado ou os filtros.'
                : 'As séries projetadas aparecerão aqui quando forem importadas para o sistema.')
            ->emptyStateIcon('heroicon-o-presentation-chart-line')
            ->emptyStateActions([
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
                TextColumn::make('indexer')
                    ->label('Indexador')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Série')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (IndexProjectionSeriesStatus $state): string => $state->label())
                    ->color(fn (IndexProjectionSeriesStatus $state): string => $state->color()),

                TextColumn::make('projection_source')
                    ->label('Fonte')
                    ->toggleable(),

                TextColumn::make('version')
                    ->label('Versão')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('reference_date')
                    ->label('Referência')
                    ->date('d/m/Y')
                    ->toggleable(),

                TextColumn::make('rates_count')
                    ->label('Linhas')
                    ->counts('rates')
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),

                TextColumn::make('importedBy.name')
                    ->label('Importada por')
                    ->color('gray')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('approvedBy.name')
                    ->label('Aprovada por')
                    ->color('gray')
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(IndexProjectionSeriesStatus::cases())
                        ->mapWithKeys(fn (IndexProjectionSeriesStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Aprovar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn ($record): bool => (auth()->user()?->can('pu.projection.approve') ?? false) && $record->status->isPendingDecision())
                    ->requiresConfirmation()
                    ->modalHeading('Aprovar série projetada')
                    ->modalDescription('A série passa a poder ser usada operacionalmente. Maker/checker: quem importou a série não pode aprová-la (exceto super admin).')
                    ->action(function ($record): void {
                        try {
                            app(IndexProjectionSeriesService::class)->approve($record, auth()->id());
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Não foi possível aprovar.')->body($exception->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title('Série projetada aprovada.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record): bool => (auth()->user()?->can('pu.projection.approve') ?? false) && $record->status->isPendingDecision())
                    ->form([
                        Textarea::make('reason')->label('Motivo da rejeição'),
                    ])
                    ->action(function ($record, array $data): void {
                        try {
                            app(IndexProjectionSeriesService::class)->reject($record, auth()->id(), $data['reason'] ?? null);
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Não foi possível rejeitar.')->body($exception->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title('Série projetada rejeitada.')->success()->send();
                    }),
                Action::make('obsolete')
                    ->label('Obsoletar')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn ($record): bool => (auth()->user()?->can('pu.projection.approve') ?? false) && $record->status !== IndexProjectionSeriesStatus::Obsolete)
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')->label('Motivo'),
                    ])
                    ->action(function ($record, array $data): void {
                        app(IndexProjectionSeriesService::class)->obsolete($record, $data['reason'] ?? null);

                        Notification::make()->title('Série marcada como obsoleta.')->success()->send();
                    }),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma série cadastrada" do "nenhuma série encontrada".
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
