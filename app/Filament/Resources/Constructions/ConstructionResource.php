<?php

namespace App\Filament\Resources\Constructions;

use App\Filament\Resources\Constructions\Pages\CreateConstruction;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Filament\Resources\Constructions\Tables\ConstructionsTable;
use App\Filament\Support\AuthorizesThroughModelPolicy;
use App\Models\Construction;
use App\Policies\ConstructionPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Autorizado pela {@see ConstructionPolicy}: as permissões
 * `emissions.*` e as guardas da obra que já tem história vivem lá.
 */
class ConstructionResource extends Resource
{
    use AuthorizesThroughModelPolicy;

    protected static ?string $model = Construction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Obras';

    protected static ?string $modelLabel = 'Obra';

    protected static ?string $pluralModelLabel = 'Obras';

    protected static ?string $recordTitleAttribute = 'development_name';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 20;

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
            SalesDiscountPoliciesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'emission',
            'measurementCompany.type',
        ]);
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
