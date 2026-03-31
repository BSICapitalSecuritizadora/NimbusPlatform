<?php

namespace App\Filament\Resources\Nimbus\PortalUsers;

use App\Filament\Resources\Nimbus\PortalUsers\Pages\CreatePortalUser;
use App\Filament\Resources\Nimbus\PortalUsers\Pages\EditPortalUser;
use App\Filament\Resources\Nimbus\PortalUsers\Pages\ListPortalUsers;
use App\Filament\Resources\Nimbus\PortalUsers\Schemas\PortalUserForm;
use App\Filament\Resources\Nimbus\PortalUsers\Tables\PortalUsersTable;
use App\Models\Nimbus\PortalUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PortalUserResource extends Resource
{
    protected static ?string $model = PortalUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Administração';

    protected static ?string $navigationLabel = 'Usuários do Portal';

    protected static ?string $modelLabel = 'usuário do portal';

    protected static ?string $pluralModelLabel = 'Usuários do Portal';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PortalUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PortalUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPortalUsers::route('/'),
            'create' => CreatePortalUser::route('/create'),
            'edit' => EditPortalUser::route('/{record}/edit'),
        ];
    }
}
