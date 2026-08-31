<?php

namespace App\Filament\Resources\MeasurementExceptions;

use App\Enums\AccessPermission;
use App\Filament\Resources\MeasurementExceptions\Pages\ListMeasurementExceptions;
use App\Models\Measurement;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MeasurementExceptionResource extends Resource
{
    protected static ?string $model = Measurement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Exceções Operacionais';

    protected static ?string $modelLabel = 'Exceção operacional';

    protected static ?string $pluralModelLabel = 'Exceções Operacionais';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 24;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();

        if (! $actor instanceof User || ! static::canViewAny()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleTo($actor);
    }

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->can(AccessPermission::MeasurementsExceptionsView->value)
            && $actor->can(AccessPermission::MeasurementsView->value);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny()
            && $record instanceof Measurement
            && (auth()->user()?->can('view', $record) ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeasurementExceptions::route('/'),
        ];
    }
}
