<?php

namespace App\Filament\Resources\BusinessHolidays;

use App\Filament\Resources\BusinessHolidays\Pages\ListBusinessHolidays;
use App\Filament\Resources\BusinessHolidays\Tables\BusinessHolidaysTable;
use App\Models\BusinessHoliday;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BusinessHolidayResource extends Resource
{
    protected static ?string $model = BusinessHoliday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $navigationLabel = 'Feriados (Calendário B3)';

    protected static ?string $modelLabel = 'Feriado';

    protected static ?string $pluralModelLabel = 'Feriados';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros Base';

    protected static ?int $navigationSort = 28;

    public static function table(Table $table): Table
    {
        return BusinessHolidaysTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('importedBy');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->can('pu.holiday.import') ?? false)
            || ($user?->can('pu.calendar.manage') ?? false)
            || ($user?->can('pu.dashboard.view') ?? false);
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

    public static function getPages(): array
    {
        return [
            'index' => ListBusinessHolidays::route('/'),
        ];
    }
}
