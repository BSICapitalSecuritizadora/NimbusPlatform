<?php

namespace App\Filament\Resources\Clients;

use App\Enums\ClientPersonType;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados Cadastrais')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('person_type')
                        ->label('Tipo de Pessoa')
                        ->badge()
                        ->formatStateUsing(fn (ClientPersonType $state): string => $state->label())
                        ->color(fn (ClientPersonType $state): string => $state->color())
                        ->columnSpanFull(),

                    TextEntry::make('name')
                        ->label(fn (Client $record): string => $record->person_type->nameLabel())
                        ->weight('bold')
                        ->columnSpanFull(),

                    TextEntry::make('trade_name')
                        ->label('Nome Fantasia')
                        ->visible(fn (Client $record): bool => filled($record->trade_name))
                        ->columnSpanFull(),

                    TextEntry::make('document')
                        ->label(fn (Client $record): string => $record->person_type->documentLabel())
                        ->formatStateUsing(fn (?string $state): string => Client::formatDocument($state)),

                    TextEntry::make('email')
                        ->label('E-mail')
                        ->placeholder('—'),

                    TextEntry::make('phone')
                        ->label('Telefone')
                        ->formatStateUsing(fn (?string $state): string => Client::formatPhone($state))
                        ->placeholder('—'),

                    TextEntry::make('deleted_at')
                        ->label('Excluído em')
                        ->dateTime('d/m/Y H:i')
                        ->color('danger')
                        ->visible(fn (Client $record): bool => $record->trashed()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'trade_name', 'document', 'email'];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('clients.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('clients.create') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('clients.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('clients.update') ?? false)
            && ! ($record instanceof Client && $record->trashed());
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can('clients.delete') ?? false)
            && ! ($record instanceof Client && $record->trashed());
    }

    public static function canRestore(Model $record): bool
    {
        return (auth()->user()?->can('clients.restore') ?? false)
            && ($record instanceof Client)
            && $record->trashed();
    }

    /**
     * Physically removing a client would take the commercial history with it, so
     * it stays out of reach of the interface.
     */
    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
