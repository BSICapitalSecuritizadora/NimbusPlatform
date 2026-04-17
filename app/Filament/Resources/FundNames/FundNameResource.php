<?php

namespace App\Filament\Resources\FundNames;

use App\Filament\Resources\FundNames\Pages\CreateFundName;
use App\Filament\Resources\FundNames\Pages\EditFundName;
use App\Filament\Resources\FundNames\Pages\ListFundNames;
use App\Filament\Resources\FundNames\Schemas\FundNameForm;
use App\Filament\Resources\FundNames\Tables\FundNamesTable;
use App\Models\FundName;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FundNameResource extends Resource
{
    protected static ?string $model = FundName::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Nomes de fundo';

    protected static ?string $modelLabel = 'Nome do fundo';

    protected static ?string $pluralModelLabel = 'Nomes de fundo';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    protected static ?string $navigationParentItem = 'Fundos';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return FundNameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundNamesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['fundType'])
            ->withCount('funds');
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
            && (! $record->funds()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundNames::route('/'),
            'create' => CreateFundName::route('/create'),
            'edit' => EditFundName::route('/{record}/edit'),
        ];
    }
}
