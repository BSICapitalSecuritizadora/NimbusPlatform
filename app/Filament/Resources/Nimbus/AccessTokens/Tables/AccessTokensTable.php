<?php

namespace App\Filament\Resources\Nimbus\AccessTokens\Tables;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Models\Nimbus\AccessToken;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccessTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('portalUser.full_name')
                    ->label('Usuário do portal')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('portalUser.email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status_label')
                    ->label('Situação')
                    ->state(fn (AccessToken $record): string => $record->status_label)
                    ->badge()
                    ->color(fn (AccessToken $record): string => $record->status_color),
                TextColumn::make('created_at')
                    ->label('Gerada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expira em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('used_at')
                    ->label('Utilizada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('used_ip')
                    ->label('IP de uso')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('validas')
                    ->label('Somente válidas')
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $query
                        ->whereNull('used_at')
                        ->whereNotIn('status', ['REVOKED', 'USED'])
                        ->where('expires_at', '>=', now())),
                \Filament\Tables\Filters\Filter::make('expiradas')
                    ->label('Somente expiradas')
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $query
                        ->whereNull('used_at')
                        ->where('status', '!=', 'REVOKED')
                        ->where('expires_at', '<', now())),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver detalhes'),
                AccessTokenResource::getRevokeAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
