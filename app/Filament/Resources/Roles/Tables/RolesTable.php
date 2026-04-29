<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Enums\AccessPermission;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
                    ->formatStateUsing(fn (string $state): string => AccessPermission::roleLabel($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissions.name')
                    ->label('Permissões')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AccessPermission::labelFor($state))
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Role $record): bool => ! in_array($record->name, RoleResource::systemRoles(), true)),
            ])
            ->toolbarActions([]);
    }
}
