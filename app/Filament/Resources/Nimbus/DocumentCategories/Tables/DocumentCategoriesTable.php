<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Tables;

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use App\Models\Nimbus\DocumentCategory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (DocumentCategory $record): ?string => auth()->user()?->can('nimbus.document-categories.update')
                ? DocumentCategoryResource::getUrl('edit', ['record' => $record], panel: 'admin')
                : null)
            ->searchPlaceholder('Buscar categoria...')
            ->defaultSort('name', 'asc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhuma categoria encontrada'
                : 'Nenhuma categoria cadastrada')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Tente outro termo de busca.'
                : 'Crie categorias para organizar e classificar os documentos da plataforma.')
            ->emptyStateIcon('heroicon-o-bookmark-square')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Nova categoria')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => DocumentCategoryResource::getUrl('create', panel: 'admin'))
                    ->visible(fn ($livewire): bool => (! static::hasActiveFiltersOrSearch($livewire)) && (auth()->user()?->can('nimbus.document-categories.create') ?? false)),

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
                    ->label('Categoria')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('general_documents_count')
                    ->label('Documentos Vinculados')
                    ->counts('generalDocuments')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $count = (int) $state;

                        return match ($count) {
                            0 => '0 documentos',
                            1 => '1 documento',
                            default => "{$count} documentos",
                        };
                    })
                    ->color(fn ($state): string => (int) $state > 0 ? 'info' : 'gray')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Data de Criação')
                    ->dateTime('d/m/Y')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (DocumentCategory $record): bool => auth()->user()?->can('nimbus.document-categories.update') ?? false),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (DocumentCategory $record): bool => auth()->user()?->can('nimbus.document-categories.delete') ?? false),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('nimbus.document-categories.delete') ?? false),
                ]),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela, para diferenciar o
     * empty state "nenhuma categoria cadastrada" de "nenhuma categoria encontrada".
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
