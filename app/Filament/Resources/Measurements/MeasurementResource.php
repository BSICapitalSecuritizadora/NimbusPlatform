<?php

namespace App\Filament\Resources\Measurements;

use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Filament\Resources\Measurements\Schemas\MeasurementInfolist;
use App\Filament\Resources\Measurements\Tables\MeasurementsTable;
use App\Models\Measurement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MeasurementResource extends Resource
{
    protected static ?string $model = Measurement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Medições';

    protected static ?string $modelLabel = 'Medição';

    protected static ?string $pluralModelLabel = 'Medições';

    protected static ?string $recordTitleAttribute = 'filename';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return MeasurementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MeasurementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MeasurementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['operation', 'assets.planSet.construction', 'reviews']);
        $user = auth()->user();

        return $user === null ? $query->whereRaw('1 = 0') : $query->visibleTo($user);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Measurement::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Measurement::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete', $record) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeasurements::route('/'),
            'create' => CreateMeasurement::route('/create'),
            'view' => ViewMeasurement::route('/{record}'),
            'edit' => EditMeasurement::route('/{record}/edit'),
        ];
    }
}
