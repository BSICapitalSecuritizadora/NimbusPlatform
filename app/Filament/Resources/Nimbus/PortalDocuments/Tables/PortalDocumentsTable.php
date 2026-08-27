<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Tables;

use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use App\Models\Nimbus\PortalDocument;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class PortalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (PortalDocument $record): ?string => auth()->user()?->can('nimbus.portal-documents.update')
                ? PortalDocumentResource::getUrl('edit', ['record' => $record], panel: 'admin')
                : null)
            ->searchPlaceholder('Buscar por usuário, e-mail, título ou arquivo...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 20, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum documento encontrado'
                : 'Nenhum documento vinculado a usuários')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Tente ajustar os filtros ou o termo pesquisado.'
                : 'Os documentos disponibilizados individualmente aos usuários do portal aparecerão aqui.')
            ->emptyStateIcon('heroicon-o-folder-open')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Novo documento do usuário')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => PortalDocumentResource::getUrl('create', panel: 'admin'))
                    ->visible(fn ($livewire): bool => (! static::hasActiveFiltersOrSearch($livewire)) && (auth()->user()?->can('nimbus.portal-documents.create') ?? false)),

                Action::make('clear_table_filters')
                    ->label('Limpar filtros')
                    ->color('gray')
                    ->visible(fn ($livewire): bool => static::hasActiveFiltersOrSearch($livewire))
                    ->action(function ($livewire): void {
                        $livewire->resetTableFilters();
                        $livewire->resetTableSearch();
                    }),
            ])
            ->columns([
                TextColumn::make('portalUser.full_name')
                    ->label('Usuário')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->weight('semibold')
                    ->description(fn (PortalDocument $record): ?string => $record->portalUser?->email)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (PortalDocument $record): ?string => $record->description ? Str::limit($record->description, 60) : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('file_original_name')
                    ->label('Arquivo')
                    ->icon('heroicon-o-document-text')
                    ->iconColor('gray')
                    ->limit(35)
                    ->tooltip(fn (PortalDocument $record): ?string => $record->file_original_name)
                    ->description(fn (PortalDocument $record): ?string => $record->file_mime)
                    ->searchable(),

                TextColumn::make('file_size')
                    ->label('Tamanho')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state, 1) : '—')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable(),

                TextColumn::make('createdBy.name')
                    ->label('Enviado por')
                    ->placeholder('—')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->color('gray')
                    ->limit(25)
                    ->tooltip(fn (PortalDocument $record): ?string => $record->createdBy?->name)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Data de Criação')
                    ->dateTime('d/m/Y H:i')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable(),

                TextColumn::make('portalUser.email')
                    ->label('E-mail')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Última Atualização')
                    ->dateTime('d/m/Y H:i')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Medium)
            ->filters([
                SelectFilter::make('nimbus_portal_user_id')
                    ->label('Usuário do Portal')
                    ->relationship('portalUser', 'full_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('created_by_user_id')
                    ->label('Enviado por')
                    ->relationship('createdBy', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('Criado a partir de'),
                        DatePicker::make('created_until')->label('Criado até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('preview')
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->url(fn (PortalDocument $record): string => route('admin.nimbus.documents.portal.preview', $record))
                        ->openUrlInNewTab()
                        ->visible(fn (PortalDocument $record): bool => filled($record->file_path) && (auth()->user()?->can('nimbus.portal-documents.view') ?? false)),

                    Action::make('download')
                        ->label('Baixar Arquivo')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->url(fn (PortalDocument $record): string => route('admin.nimbus.documents.portal.download', $record))
                        ->visible(fn (PortalDocument $record): bool => filled($record->file_path) && (auth()->user()?->can('nimbus.portal-documents.view') ?? false)),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (PortalDocument $record): bool => auth()->user()?->can('nimbus.portal-documents.update') ?? false),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (PortalDocument $record): bool => auth()->user()?->can('nimbus.portal-documents.delete') ?? false),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do documento'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('nimbus.portal-documents.delete') ?? false),
                ]),
            ]);
    }

    /**
     * Verifica se há busca ou filtros aplicados na tabela.
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
