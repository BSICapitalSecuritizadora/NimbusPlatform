<?php

namespace App\Filament\Resources\Nimbus\AccessTokens;

use App\Filament\Resources\Nimbus\AccessTokens\Pages\ListAccessTokens;
use App\Filament\Resources\Nimbus\AccessTokens\Pages\ViewAccessToken;
use App\Filament\Resources\Nimbus\AccessTokens\Tables\AccessTokensTable;
use App\Models\Nimbus\AccessToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccessTokenResource extends Resource
{
    protected static ?string $model = AccessToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static \UnitEnum|string|null $navigationGroup = 'Gestão Documental Externa';

    protected static ?string $navigationParentItem = 'Administração';

    protected static ?string $navigationLabel = 'Chaves de Acesso';

    protected static ?string $modelLabel = 'chave de acesso';

    protected static ?string $pluralModelLabel = 'Chaves de Acesso';

    protected static ?string $slug = 'gestao-documental-externa/access-tokens';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalhes da chave')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('portalUser.full_name')
                            ->label('Usuário do portal')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('portalUser.email')
                            ->label('E-mail')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('code')
                            ->label('Código')
                            ->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('status_label')
                            ->label('Situação')
                            ->badge()
                            ->color(fn (AccessToken $record): string => $record->status_color),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->label('Gerada em')
                            ->dateTime('d/m/Y H:i'),
                        \Filament\Infolists\Components\TextEntry::make('expires_at')
                            ->label('Expira em')
                            ->dateTime('d/m/Y H:i'),
                        \Filament\Infolists\Components\TextEntry::make('used_at')
                            ->label('Utilizada em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('used_ip')
                            ->label('IP de uso')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('used_user_agent')
                            ->label('Navegador / dispositivo')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return AccessTokensTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('nimbus.access-tokens.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('nimbus.access-tokens.delete') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('portalUser');
    }

    public static function getRevokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revogar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revogar chave de acesso')
            ->modalDescription('A chave será invalidada imediatamente e não poderá mais ser usada no portal.')
            ->visible(fn (AccessToken $record): bool => $record->isValid() && (auth()->user()?->can('nimbus.access-tokens.update') ?? false))
            ->action(function (AccessToken $record): void {
                $record->update(['status' => 'REVOKED']);

                Notification::make()
                    ->title('Chave revogada com sucesso.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessTokens::route('/'),
            'view' => ViewAccessToken::route('/{record}'),
        ];
    }
}
