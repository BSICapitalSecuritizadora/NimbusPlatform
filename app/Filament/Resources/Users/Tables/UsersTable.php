<?php

namespace App\Filament\Resources\Users\Tables;

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
                TextColumn::make('approved_at')
                    ->label('Status de acesso')
                    ->badge()
                    ->state(fn (User $record): string => $record->isApproved() ? 'Aprovado' : 'Aguardando aprovação')
                    ->color(fn (User $record): string => $record->isApproved() ? 'success' : 'warning')
                    ->sortable(),
                TextColumn::make('invitedByUser.name')
                    ->label('Convidado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Cadastrado em')
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
