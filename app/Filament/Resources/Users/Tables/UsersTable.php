<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\AccessPermission;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Delegations\DelegationHistoryDeleteGuard;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable()
                    ->wrap(false),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('E-mail copiado')
                    ->tooltip(fn (User $record): ?string => strlen((string) $record->email) > 32 ? $record->email : null)
                    ->wrap(false),
                TextColumn::make('cargo')
                    ->label('Cargo')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('departamento')
                    ->label('Departamento')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Perfis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AccessPermission::roleLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        'super-admin' => 'warning',
                        'admin' => 'primary',
                        default => 'gray',
                    })
                    ->placeholder('Sem perfil'),
                TextColumn::make('is_active')
                    ->label('Status de acesso')
                    ->badge()
                    ->state(fn (User $record): string => match (true) {
                        ! $record->isApproved() => 'Pendente',
                        ! $record->isActive() => 'Inativo',
                        default => 'Ativo',
                    })
                    ->color(fn (User $record): string => match (true) {
                        ! $record->isApproved() => 'warning',
                        ! $record->isActive() => 'danger',
                        default => 'success',
                    })
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Último login')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('azure_id')
                    ->label('Microsoft ID')
                    ->placeholder('Aguardando login')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invitedByUser.name')
                    ->label('Convidado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Data de registro')
                    ->dateTime('d/m/Y · H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Perfil')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => AccessPermission::roleLabel($record->name)),
                SelectFilter::make('is_active')
                    ->label('Status de acesso')
                    ->options([
                        '1' => 'Ativo',
                        '0' => 'Inativo',
                    ]),
                SelectFilter::make('departamento')
                    ->label('Departamento')
                    ->options(fn () => User::query()->whereNotNull('departamento')->where('departamento', '!=', '')->distinct()->pluck('departamento', 'departamento')->toArray()),
            ])
            ->searchPlaceholder('Buscar por nome ou e-mail...')
            ->stackedOnMobile()
            ->recordUrl(fn (User $record): ?string => UserResource::canEdit($record) ? UserResource::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([
                UserResource::getApproveUserAction(),
                EditAction::make()
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray')
                    ->tooltip('Editar usuário'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // O lote é recusado inteiro quando qualquer selecionado tem
                    // histórico de delegação: exclusão parcial deixaria a pessoa
                    // sem saber o que foi apagado e o que sobrou.
                    DeleteBulkAction::make()
                        ->before(fn (Collection $records, DeleteBulkAction $action) => DelegationHistoryDeleteGuard::haltForUsers($records, $action)),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(fn (ListUsers $livewire): string => self::hasActiveSearch($livewire)
                ? 'Nenhum usuário encontrado'
                : 'Nenhum usuário cadastrado')
            ->emptyStateDescription(fn (ListUsers $livewire): ?string => self::hasActiveSearch($livewire)
                ? null
                : 'Cadastre um usuário para conceder acesso à plataforma.')
            ->emptyStateActions([
                Action::make('createUser')
                    ->label('Criar primeiro usuário')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => UserResource::getUrl('create'))
                    ->visible(fn (ListUsers $livewire): bool => UserResource::canCreate() && (! self::hasActiveSearch($livewire))),
                Action::make('clearTableSearch')
                    ->label('Limpar busca')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->action(fn (ListUsers $livewire) => $livewire->resetTableSearch())
                    ->visible(fn (ListUsers $livewire): bool => self::hasActiveSearch($livewire)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected static function hasActiveSearch(ListUsers $livewire): bool
    {
        return filled($livewire->tableSearch);
    }
}
