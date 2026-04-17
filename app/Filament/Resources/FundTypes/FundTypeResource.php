<?php

namespace App\Filament\Resources\FundTypes;

use App\Filament\Resources\FundTypes\Pages\CreateFundType;
use App\Filament\Resources\FundTypes\Pages\EditFundType;
use App\Filament\Resources\FundTypes\Pages\ListFundTypes;
use App\Filament\Resources\FundTypes\Schemas\FundTypeForm;
use App\Filament\Resources\FundTypes\Tables\FundTypesTable;
use App\Models\FundType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FundTypeResource extends Resource
{
    protected static ?string $model = FundType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Tipos de fundo';

    protected static ?string $modelLabel = 'Tipo de fundo';

    protected static ?string $pluralModelLabel = 'Tipos de fundo';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    protected static ?string $navigationParentItem = 'Fundos';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return FundTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'fundNames',
            'funds',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('funds.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('funds.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('funds.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can('funds.delete') ?? false)
            && (! $record->fundNames()->exists())
            && (! $record->funds()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundTypes::route('/'),
            'create' => CreateFundType::route('/create'),
            'edit' => EditFundType::route('/{record}/edit'),
        ];
    }
}
