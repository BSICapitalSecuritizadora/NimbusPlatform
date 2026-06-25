<?php

namespace App\Filament\Resources\IndexRates;

use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Filament\Resources\IndexRates\Tables\IndexRatesTable;
use App\Models\IndexRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class IndexRateResource extends Resource
{
    protected static ?string $model = IndexRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Índices (CDI/IPCA)';

    protected static ?string $modelLabel = 'Índice';

    protected static ?string $pluralModelLabel = 'Índices';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros Base';

    protected static ?int $navigationSort = 27;

    public static function table(Table $table): Table
    {
        return IndexRatesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('projectionSeries');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->can('pu.index.import') ?? false) || ($user?->can('pu.dashboard.view') ?? false);
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
            'index' => ListIndexRates::route('/'),
        ];
    }
}
