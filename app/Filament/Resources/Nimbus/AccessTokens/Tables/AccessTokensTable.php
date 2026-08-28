<?php

namespace App\Filament\Resources\Nimbus\AccessTokens\Tables;

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Models\Nimbus\AccessToken;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccessTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('portalUser.full_name')
                    ->label('Usuário do Portal')
                    ->weight('semibold')
                    ->icon(Heroicon::OutlinedUser)
                    ->description(fn (AccessToken $record): ?string => $record->portalUser?->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('portalUser', function (Builder $userQuery) use ($search): Builder {
                            return $userQuery->where('full_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(),

                TextColumn::make('portalUser.email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status_label')
                    ->label('Situação')
                    ->state(fn (AccessToken $record): string => $record->status_label)
                    ->badge()
                    ->color(fn (AccessToken $record): string => $record->status_color)
                    ->icon(fn (AccessToken $record): string => match (true) {
                        $record->isUsed() => 'heroicon-o-check-circle',
                        $record->isRevoked() => 'heroicon-o-x-circle',
                        $record->isExpired() => 'heroicon-o-clock',
                        default => 'heroicon-o-key',
                    }),

                TextColumn::make('created_at')
                    ->label('Data de Geração')
                    ->dateTime('d/m/Y · H:i')
                    ->fontFamily('mono')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Data de Expiração')
                    ->dateTime('d/m/Y · H:i')
                    ->fontFamily('mono')
                    ->description(function (AccessToken $record): ?string {
                        if (! $record->expires_at) {
                            return null;
                        }

                        if ($record->isRevoked()) {
                            return 'Revogada';
                        }

                        if ($record->isUsed()) {
                            return 'Utilizada';
                        }

                        if ($record->isExpired()) {
                            return 'Expirada '.$record->expires_at->diffForHumans();
                        }

                        return 'Expira em '.$record->expires_at->diffForHumans(null, true);
                    })
                    ->sortable(),

                TextColumn::make('used_at')
                    ->label('Data de Utilização')
                    ->dateTime('d/m/Y · H:i')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('used_ip')
                    ->label('Endereço IP')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options([
                        'valid' => 'Válidas',
                        'used' => 'Utilizadas',
                        'expired' => 'Expiradas',
                        'revoked' => 'Revogadas',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'valid' => $query->whereNull('used_at')->whereNotIn('status', ['REVOKED', 'USED'])->where('expires_at', '>=', now()),
                            'used' => $query->where(fn (Builder $sq) => $sq->whereNotNull('used_at')->orWhere('status', 'USED')),
                            'expired' => $query->whereNull('used_at')->where('status', '!=', 'REVOKED')->where('expires_at', '<', now()),
                            'revoked' => $query->where('status', 'REVOKED'),
                            default => $query,
                        };
                    }),

                Filter::make('created_at')
                    ->label('Data de Geração')
                    ->form([
                        DatePicker::make('created_from')
                            ->label('Gerada a partir de'),
                        DatePicker::make('created_until')
                            ->label('Gerada até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar Detalhes')
                        ->icon(Heroicon::OutlinedEye),
                    AccessTokenResource::getRevokeAction(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da chave'),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma chave de acesso registrada')
            ->emptyStateDescription('As chaves geradas para usuários do portal aparecerão aqui para acompanhamento.')
            ->emptyStateIcon(Heroicon::OutlinedKey)
            ->searchPlaceholder('Buscar por usuário ou e-mail...')
            ->searchOnBlur(false)
            ->striped()
            ->defaultSort('created_at', 'desc');
    }
}
