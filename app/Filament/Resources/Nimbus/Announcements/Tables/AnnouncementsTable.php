<?php

namespace App\Filament\Resources\Nimbus\Announcements\Tables;

use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Nimbus\Announcement;
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
use Illuminate\Support\Str;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Announcement $record): ?string => auth()->user()?->can('nimbus.announcements.update')
                ? AnnouncementResource::getUrl('edit', ['record' => $record], panel: 'admin')
                : null)
            ->searchPlaceholder('Buscar por título, nível ou autor...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 20, 50, 100])
            ->emptyStateHeading(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Nenhum aviso encontrado'
                : 'Nenhum aviso geral cadastrado')
            ->emptyStateDescription(fn ($livewire): string => static::hasActiveFiltersOrSearch($livewire)
                ? 'Tente ajustar os filtros ou o termo pesquisado.'
                : 'Crie um aviso para comunicar informações importantes aos usuários da plataforma.')
            ->emptyStateIcon('heroicon-o-megaphone')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Novo aviso')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => AnnouncementResource::getUrl('create', panel: 'admin'))
                    ->visible(fn ($livewire): bool => (! static::hasActiveFiltersOrSearch($livewire)) && (auth()->user()?->can('nimbus.announcements.create') ?? false)),

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
                TextColumn::make('title')
                    ->label('Título')
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (Announcement $record): ?string => $record->body ? Str::limit($record->body, 65) : null)
                    ->tooltip(fn (Announcement $record): ?string => $record->title)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('level')
                    ->label('Nível')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'info' => 'Informativo',
                        'success' => 'Sucesso',
                        'warning' => 'Atenção',
                        'danger' => 'Crítico',
                        default => ucfirst($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'info' => 'info',
                        'success' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => match ($state) {
                        'info' => 'heroicon-m-information-circle',
                        'success' => 'heroicon-m-check-circle',
                        'warning' => 'heroicon-m-exclamation-triangle',
                        'danger' => 'heroicon-m-exclamation-circle',
                        default => null,
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->state(function (Announcement $record): string {
                        if (! $record->is_active) {
                            return 'inativo';
                        }
                        if ($record->starts_at && $record->starts_at->isFuture()) {
                            return 'agendado';
                        }
                        if ($record->ends_at && $record->ends_at->isPast()) {
                            return 'encerrado';
                        }

                        return 'ativo';
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ativo' => 'Ativo',
                        'agendado' => 'Agendado',
                        'encerrado' => 'Encerrado',
                        'inativo' => 'Inativo',
                        default => ucfirst($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ativo' => 'success',
                        'agendado' => 'info',
                        'encerrado' => 'gray',
                        'inativo' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => match ($state) {
                        'ativo' => 'heroicon-m-check-circle',
                        'agendado' => 'heroicon-m-clock',
                        'encerrado' => 'heroicon-m-no-symbol',
                        'inativo' => 'heroicon-m-x-circle',
                        default => null,
                    }),

                TextColumn::make('starts_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-300'])
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Fim')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-300'])
                    ->sortable(),

                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->placeholder('—')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->color('gray')
                    ->limit(25)
                    ->tooltip(fn (Announcement $record): ?string => $record->createdBy?->name)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Data de Criação')
                    ->dateTime('d/m/Y H:i')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Última Atualização')
                    ->dateTime('d/m/Y H:i')
                    ->extraAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap text-xs text-gray-400'])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Medium)
            ->filters([
                SelectFilter::make('level')
                    ->label('Nível')
                    ->options([
                        'info' => 'Informativo',
                        'success' => 'Sucesso',
                        'warning' => 'Atenção',
                        'danger' => 'Crítico',
                    ]),

                SelectFilter::make('is_active')
                    ->label('Publicado')
                    ->options([
                        1 => 'Ativo no portal',
                        0 => 'Inativo no portal',
                    ]),

                SelectFilter::make('created_by_user_id')
                    ->label('Criado por')
                    ->relationship('createdBy', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

                Filter::make('vigencia')
                    ->form([
                        DatePicker::make('starts_from')->label('Início a partir de'),
                        DatePicker::make('ends_until')->label('Fim até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['starts_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('starts_at', '>=', $date),
                            )
                            ->when(
                                $data['ends_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('ends_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Announcement $record): bool => auth()->user()?->can('nimbus.announcements.update') ?? false),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (Announcement $record): bool => auth()->user()?->can('nimbus.announcements.delete') ?? false),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do aviso'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('nimbus.announcements.delete') ?? false),
                ]),
            ]);
    }

    /**
     * Verifica se há busca ou filtros ativos na tabela.
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
