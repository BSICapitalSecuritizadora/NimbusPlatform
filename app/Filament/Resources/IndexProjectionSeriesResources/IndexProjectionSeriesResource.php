<?php

namespace App\Filament\Resources\IndexProjectionSeriesResources;

use App\Filament\Resources\IndexProjectionSeriesResources\Pages\ListIndexProjectionSeries;
use App\Filament\Resources\IndexProjectionSeriesResources\Tables\IndexProjectionSeriesTable;
use App\Models\IndexProjectionSeries;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class IndexProjectionSeriesResource extends Resource
{
    protected static ?string $model = IndexProjectionSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Séries Projetadas IPCA';

    protected static ?string $modelLabel = 'Série projetada';

    protected static ?string $pluralModelLabel = 'Séries projetadas';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros Base';

    protected static ?int $navigationSort = 28;

    public static function table(Table $table): Table
    {
        return IndexProjectionSeriesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->can('pu.projection.approve') ?? false)
            || ($user?->can('pu.index.import') ?? false)
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
            'index' => ListIndexProjectionSeries::route('/'),
        ];
    }
}
