<?php

namespace App\Filament\Resources\Emissions;

use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Filament\Resources\Emissions\Tables\EmissionsTable;
use App\Models\Emission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class EmissionResource extends Resource
{
    protected static ?string $model = Emission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Emissões';

    protected static ?string $modelLabel = 'Emissão';

    protected static ?string $pluralModelLabel = 'Emissões';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return EmissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmissionsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('emissions.view');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('emissions.create');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('emissions.delete');
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
            'index' => ListEmissions::route('/'),
            'create' => CreateEmission::route('/create'),
            'edit' => EditEmission::route('/{record}/edit'),
        ];
    }
}
