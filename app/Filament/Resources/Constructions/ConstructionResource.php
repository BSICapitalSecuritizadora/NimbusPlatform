<?php

namespace App\Filament\Resources\Constructions;

use App\Filament\Resources\Constructions\Pages\CreateConstruction;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Filament\Resources\Constructions\Tables\ConstructionsTable;
use App\Models\Construction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ConstructionResource extends Resource
{
    protected static ?string $model = Construction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Obras';

    protected static ?string $modelLabel = 'Obra';

    protected static ?string $pluralModelLabel = 'Obras';

    protected static ?string $recordTitleAttribute = 'development_name';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return ConstructionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConstructionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'emission',
            'measurementCompany.type',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('emissions.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('emissions.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('emissions.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('emissions.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConstructions::route('/'),
            'create' => CreateConstruction::route('/create'),
            'edit' => EditConstruction::route('/{record}/edit'),
        ];
    }
}
