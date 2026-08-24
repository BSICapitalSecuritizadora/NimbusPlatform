<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\ClientPersonType;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Client $record): ?string => ClientResource::canView($record)
                ? ClientResource::getUrl('view', ['record' => $record])
                : null)
            ->searchPlaceholder('Buscar por nome, CPF/CNPJ, e-mail ou telefone...')
            ->defaultSort('name')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('person_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (ClientPersonType $state): string => $state->label())
                    ->color(fn (ClientPersonType $state): string => $state->color())
                    ->sortable(),

                // Only worth a column where trashed clients can show up: on the
                // active listing every row would repeat the same "Ativo".
                TextColumn::make('deleted_at')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Client $record): string => $record->trashed() ? 'Excluído' : 'Ativo')
                    ->color(fn (Client $record): string => $record->trashed() ? 'danger' : 'success')
                    ->icon(fn (Client $record): string => $record->trashed() ? 'heroicon-m-trash' : 'heroicon-m-check-circle')
                    ->description(fn (Client $record): ?string => $record->trashed()
                        ? 'em '.$record->deleted_at->format('d/m/Y H:i')
                        : null)
                    ->visible(fn (mixed $livewire): bool => self::isShowingTrashed($livewire))
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nome / Razão Social')
                    ->description(fn (Client $record): ?string => $record->trade_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('trade_name', 'like', "%{$search}%"))
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('document')
                    ->label('CPF/CNPJ')
                    ->formatStateUsing(fn (?string $state): string => Client::formatDocument($state))
                    // Digits only in the database, so the search term is
                    // normalized and matches with or without punctuation.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->when(
                        Str::digitsOnly($search) !== '',
                        fn (Builder $query): Builder => $query->orWhere('document', 'like', '%'.Str::digitsOnly($search).'%'),
                    ))
                    ->copyable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Telefone')
                    ->formatStateUsing(fn (?string $state): string => Client::formatPhone($state))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->when(
                        Str::digitsOnly($search) !== '',
                        fn (Builder $query): Builder => $query->orWhere('phone', 'like', '%'.Str::digitsOnly($search).'%'),
                    ))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Cadastrado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filters([
                SelectFilter::make('person_type')
                    ->label('Tipo de Pessoa')
                    ->options(ClientPersonType::options()),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (Client $record): bool => ClientResource::canView($record)),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (Client $record): bool => ClientResource::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->modalHeading('Excluir cliente')
                        ->modalDescription('O cadastro deixa de aparecer na listagem, mas é preservado para manter o histórico comercial.')
                        ->visible(fn (Client $record): bool => ClientResource::canDelete($record)),

                    RestoreAction::make()
                        ->label('Restaurar')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalHeading('Restaurar cliente')
                        ->modalDescription('O mesmo cadastro volta para a listagem de clientes ativos, com todos os dados e contratos já vinculados. Nenhum cliente novo é criado.')
                        ->modalSubmitActionLabel('Restaurar cadastro')
                        ->successNotificationTitle('Cliente restaurado com sucesso.')
                        ->visible(fn (Client $record): bool => ClientResource::canRestore($record)),

                    ForceDeleteAction::make()
                        ->label('Excluir definitivamente')
                        ->visible(fn (Client $record): bool => ClientResource::canForceDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações do cliente'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // The built-in visibility of both bulk actions keys off the
                    // TrashedFilter, which the Ativos/Excluídos/Todos tabs
                    // replaced -- so each one states its own condition.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(fn (Client $record): bool => ClientResource::canDelete($record))
                        ->visible(fn (mixed $livewire): bool => (auth()->user()?->can('clients.delete') ?? false)
                            && ! self::isOnlyTrashed($livewire)),

                    RestoreBulkAction::make()
                        ->label('Restaurar selecionados')
                        ->modalHeading('Restaurar clientes')
                        ->modalDescription('Os cadastros selecionados voltam para a listagem de clientes ativos, com todos os dados e contratos já vinculados. Nenhum cliente novo é criado.')
                        ->modalSubmitActionLabel('Restaurar cadastros')
                        ->successNotificationTitle('Clientes restaurados com sucesso.')
                        // A string ability would go through a policy this model
                        // does not have; the resource is the single place where
                        // the clients.* permissions are interpreted.
                        ->authorizeIndividualRecords(fn (Client $record): bool => ClientResource::canRestore($record))
                        ->visible(fn (mixed $livewire): bool => (auth()->user()?->can('clients.restore') ?? false)
                            && self::isShowingTrashed($livewire)),
                ]),
            ])
            ->emptyStateHeading(fn (mixed $livewire): string => self::isOnlyTrashed($livewire)
                ? 'Nenhum cliente excluído'
                : 'Nenhum cliente cadastrado');
    }

    /**
     * Whether the current listing may contain trashed clients at all.
     */
    private static function isShowingTrashed(mixed $livewire): bool
    {
        return ($livewire instanceof ListClients)
            && in_array($livewire->activeTab, [ListClients::TAB_TRASHED, ListClients::TAB_ALL], true);
    }

    /**
     * Whether the current listing contains nothing but trashed clients.
     */
    private static function isOnlyTrashed(mixed $livewire): bool
    {
        return ($livewire instanceof ListClients)
            && ($livewire->activeTab === ListClients::TAB_TRASHED);
    }
}
