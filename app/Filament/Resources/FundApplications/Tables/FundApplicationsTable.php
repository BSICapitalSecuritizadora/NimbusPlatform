<?php

namespace App\Filament\Resources\FundApplications\Tables;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use App\Filament\Resources\Funds\FundResource;
use App\Models\FundApplication;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (FundApplication $record): ?string => FundApplicationResource::canEdit($record)
                ? FundApplicationResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(FundApplication::query()->exists())
            ->searchPlaceholder('Buscar aplicação...')
            ->searchDebounce('400ms')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma aplicação corresponde à busca aplicada'
                : 'Nenhuma aplicação cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste ou limpe a busca para visualizar as aplicações cadastradas.'
                : 'Cadastre a primeira aplicação para utilizá-la na configuração dos fundos.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeira aplicação')
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => ! static::hasActiveFiltersOrSearch($livewire)),

                Action::make('clear_table_search')
                    ->label('Limpar busca')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveFiltersOrSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableSearch();
                    }),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->weight('semibold')
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '—',
                        1 => '1 fundo',
                        default => "{$state} fundos",
                    })
                    ->color(fn (int $state): ?string => $state === 0 ? 'gray' : null)
                    ->url(fn (FundApplication $record): ?string => $record->funds_count > 0
                        ? FundResource::getUrl('index', ['tableFilters' => ['fund_application_id' => ['value' => $record->getKey()]]])
                        : null),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma aplicação cadastrada" do "nenhuma corresponde à busca".
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
