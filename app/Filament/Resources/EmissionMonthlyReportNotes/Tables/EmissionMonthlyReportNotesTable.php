<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes\Tables;

use App\Filament\Resources\EmissionMonthlyReportNotes\EmissionMonthlyReportNoteResource;
use App\Models\EmissionMonthlyReportNote;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EmissionMonthlyReportNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (EmissionMonthlyReportNote $record): ?string => EmissionMonthlyReportNoteResource::canEdit($record)
                ? EmissionMonthlyReportNoteResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(EmissionMonthlyReportNote::query()->exists())
            ->searchPlaceholder('Buscar por título ou emissão...')
            ->defaultSort('reference_month', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma nota corresponde aos filtros selecionados'
                : 'Nenhuma nota explicativa cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste a busca ou limpe os filtros para visualizar as notas cadastradas.'
                : 'Cadastre a primeira nota explicativa para organizar observações complementares por emissão e competência.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Cadastrar nota explicativa')
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
                TextColumn::make('title')
                    ->label('Título')
                    ->weight('semibold')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->placeholder('—'),

                TextColumn::make('is_visible_on_report')
                    ->label('No relatório')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Incluída' : 'Oculta')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->alignCenter(),

                TextColumn::make('createdBy.name')
                    ->label('Autor')
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters(
                EmissionMonthlyReportNote::query()->exists()
                    ? [
                        SelectFilter::make('emission_id')
                            ->label('Emissão')
                            ->relationship('emission', 'name')
                            ->searchable()
                            ->preload(),

                        SelectFilter::make('reference_month')
                            ->label('Competência')
                            ->options(fn (): array => EmissionMonthlyReportNote::query()
                                ->orderByDesc('reference_month')
                                ->pluck('reference_month')
                                ->filter()
                                ->unique()
                                ->mapWithKeys(fn (mixed $referenceMonth): array => [
                                    (string) EmissionMonthlyReportNote::normalizeReferenceMonth($referenceMonth) => EmissionMonthlyReportNote::formatReferenceMonthForDisplay($referenceMonth),
                                ])
                                ->all()),

                        TernaryFilter::make('is_visible_on_report')
                            ->label('No relatório'),
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
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma nota cadastrada" do "nenhuma corresponde aos filtros".
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
