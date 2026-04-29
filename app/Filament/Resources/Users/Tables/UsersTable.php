<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\AccessPermission;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),
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
                    ->placeholder('Sem perfil'),
                TextColumn::make('is_active')
                    ->label('Status de acesso')
                    ->badge()
                    ->state(fn (User $record): string => $record->isActive() ? 'Ativo' : 'Inativo')
                    ->color(fn (User $record): string => $record->isActive() ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Último login')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('azure_id')
                    ->label('Microsoft ID')
                    ->placeholder('Aguardando login')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invitedByUser.name')
                    ->label('Convidado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Data de Registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                UserResource::getApproveUserAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
 'desc');
    }
}
