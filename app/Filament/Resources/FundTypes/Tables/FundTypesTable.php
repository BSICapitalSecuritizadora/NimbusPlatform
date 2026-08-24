<?php

namespace App\Filament\Resources\FundTypes\Tables;

use App\Filament\Resources\FundNames\FundNameResource;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Resources\FundTypes\FundTypeResource;
use App\Models\FundType;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (FundType $record): ?string => FundTypeResource::canEdit($record)
                ? FundTypeResource::getUrl('edit', ['record' => $record])
                : null)
            ->searchable(FundType::query()->exists())
            ->searchPlaceholder('Buscar tipo de fundo...')
            ->searchDebounce('400ms')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum tipo de fundo corresponde à busca aplicada'
                : 'Nenhum tipo de fundo cadastrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Ajuste ou limpe a busca para visualizar os tipos de fundo cadastrados.'
                : 'Crie o primeiro tipo para organizar e classificar os fundos das operações.')
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Criar primeiro tipo de fundo')
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

                TextColumn::make('fund_names_count')
                    ->label('Nomes vinculados')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '—',
                        1 => '1 nome',
                        default => "{$state} nomes",
                    })
                    ->color(fn (int $state): ?string => $state === 0 ? 'gray' : null)
                    ->url(fn (FundType $record): ?string => $record->fund_names_count > 0
                        ? FundNameResource::getUrl('index', ['tableFilters' => ['fund_type_id' => ['value' => $record->getKey()]]])
                        : null),

                TextColumn::make('funds_count')
                    ->label('Fundos vinculados')
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => '—',
                        1 => '1 fundo',
                        default => "{$state} fundos",
                    })
                    ->color(fn (int $state): ?string => $state === 0 ? 'gray' : null)
                    ->url(fn (FundType $record): ?string => $record->funds_count > 0
                        ? FundResource::getUrl('index', ['tableFilters' => ['fund_type_id' => ['value' => $record->getKey()]]])
                        : null),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhum tipo cadastrado" do "nenhum corresponde à busca".
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
