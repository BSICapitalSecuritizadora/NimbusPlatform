<?php

namespace App\Filament\Resources\ConstructionUnits;

use App\Filament\Resources\ConstructionUnits\Pages\CreateConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\EditConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Schemas\ConstructionUnitForm;
use App\Filament\Resources\ConstructionUnits\Tables\ConstructionUnitsTable;
use App\Models\ConstructionUnit;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ConstructionUnitResource extends Resource
{
    protected static ?string $model = ConstructionUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Unidades';

    protected static ?string $modelLabel = 'Unidade';

    protected static ?string $pluralModelLabel = 'Unidades';

    protected static ?string $recordTitleAttribute = 'unit';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return ConstructionUnitForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Unidade')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('construction.emission.name')
                        ->label('Emissão')
                        ->columnSpanFull(),
                    TextEntry::make('construction.development_name')
                        ->label('Empreendimento')
                        ->columnSpanFull(),
                    TextEntry::make('block')->label('Bloco'),
                    TextEntry::make('unit')->label('Unidade')->weight('bold'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ConstructionUnitsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'construction.emission',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['unit', 'block', 'construction.development_name', 'construction.emission.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->display_name;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('constructions.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('constructions.create') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('constructions.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('constructions.update') ?? false;
    }

    /**
     * A unit that already carries contracts is commercial history: removing it
     * would orphan the sales made on it, so the database refuses and the action
     * is not offered.
     */
    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can('constructions.delete') ?? false)
            && ! ($record instanceof ConstructionUnit && $record->contracts()->withTrashed()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConstructionUnits::route('/'),
            'create' => CreateConstructionUnit::route('/create'),
            'view' => ViewConstructionUnit::route('/{record}'),
            'edit' => EditConstructionUnit::route('/{record}/edit'),
        ];
    }
}
