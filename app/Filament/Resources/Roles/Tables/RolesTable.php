<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Enums\AccessPermission;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Perfil')
                    ->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn (string $state): string => AccessPermission::roleLabel($state))
                    ->description(fn (Role $record): ?string => $record->name === 'super-admin'
                        ? 'Acesso irrestrito a todas as áreas e funcionalidades'
                        : null)
                    ->badge(fn (Role $record): bool => $record->name === 'super-admin')
                    ->color(fn (Role $record): ?string => $record->name === 'super-admin' ? 'warning' : null)
                    ->searchable()
                    ->sortable()
                    ->wrap(false),

                TextColumn::make('permissions_summary')
                    ->label('Alcance e Permissões')
                    ->state(function (Role $record): string {
                        $perms = $record->permissions;
                        $count = $perms->count();

                        if ($record->name === 'super-admin') {
                            return "{$count} permissões · Acesso integral a todos os 12 módulos";
                        }

                        $modules = $perms->map(fn ($p) => AccessPermission::tryFrom($p->name)?->module() ?? 'Outros')
                            ->unique()
                            ->values()
                            ->all();

                        $moduleCount = count($modules);
                        $label = $count === 1 ? 'permissão' : 'permissões';
                        $modLabel = $moduleCount === 1 ? 'módulo' : 'módulos';

                        if ($moduleCount === 0) {
                            return 'Nenhuma permissão concedida';
                        }

                        $preview = implode(' · ', array_slice($modules, 0, 4));
                        $extra = $moduleCount > 4 ? ' · +'.($moduleCount - 4).' módulos' : '';

                        return "{$count} {$label} ({$moduleCount} {$modLabel}): {$preview}{$extra}";
                    })
                    ->wrap(),

                TextColumn::make('users_count')
                    ->label('Usuários vinculados')
                    ->counts('users')
                    ->badge()
                    ->color('gray')
                    ->suffix(fn ($state) => (int) $state === 1 ? ' usuário' : ' usuários')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y · H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder('Buscar perfil...')
            ->recordUrl(fn (Role $record): ?string => RoleResource::canEdit($record) ? RoleResource::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([
                EditAction::make()
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray')
                    ->tooltip('Editar perfil'),
                DeleteAction::make()
                    ->visible(fn (Role $record): bool => ! in_array($record->name, RoleResource::systemRoles(), true)),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(fn (ListRoles $livewire): string => self::hasActiveSearch($livewire)
                ? 'Nenhum perfil encontrado'
                : 'Nenhum perfil de acesso cadastrado')
            ->emptyStateDescription(fn (ListRoles $livewire): ?string => self::hasActiveSearch($livewire)
                ? null
                : 'Crie um perfil para organizar conjuntos de permissões dos usuários.')
            ->emptyStateActions([
                Action::make('createRole')
                    ->label('Criar primeiro perfil')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->url(fn (): string => RoleResource::getUrl('create'))
                    ->visible(fn (ListRoles $livewire): bool => RoleResource::canCreate() && (! self::hasActiveSearch($livewire))),
                Action::make('clearTableSearch')
                    ->label('Limpar busca')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->action(fn (ListRoles $livewire) => $livewire->resetTableSearch())
                    ->visible(fn (ListRoles $livewire): bool => self::hasActiveSearch($livewire)),
            ])
            ->defaultSort('name', 'asc');
    }

    protected static function hasActiveSearch(ListRoles $livewire): bool
    {
        return filled($livewire->tableSearch);
    }
}
