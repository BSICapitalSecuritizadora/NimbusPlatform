<?php

namespace App\Filament\Resources\Nimbus\Announcements;

use App\Filament\Resources\Nimbus\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Nimbus\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Nimbus\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Nimbus\Announcements\Schemas\AnnouncementForm;
use App\Filament\Resources\Nimbus\Announcements\Tables\AnnouncementsTable;
use App\Models\Nimbus\Announcement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Comunicação';

    protected static ?string $navigationLabel = 'Avisos Gerais';

    protected static ?string $modelLabel = 'aviso geral';

    protected static ?string $pluralModelLabel = 'Avisos Gerais';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('createdBy');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
