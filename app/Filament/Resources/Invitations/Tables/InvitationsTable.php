<?php

namespace App\Filament\Resources\Invitations\Tables;

use App\Models\Invitation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('invitedBy.name')
                    ->label('Convidado por')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expira em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('used_at')
                    ->label('Utilizado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Não utilizado')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Invitation $record): string => match (true) {
                        $record->used_at !== null => 'Utilizado',
                        $record->expires_at->isPast() => 'Expirado',
                        default => 'Pendente',
                    })
                    ->color(fn (Invitation $record): string => match (true) {
                        $record->used_at !== null => 'success',
                        $record->expires_at->isPast() => 'danger',
                        default => 'warning',
                    }),
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
