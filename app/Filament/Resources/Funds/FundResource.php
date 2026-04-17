<?php

namespace App\Filament\Resources\Funds;

use App\Filament\Resources\Funds\Pages\CreateFund;
use App\Filament\Resources\Funds\Pages\EditFund;
use App\Filament\Resources\Funds\Pages\ListFunds;
use App\Filament\Resources\Funds\Schemas\FundForm;
use App\Filament\Resources\Funds\Tables\FundsTable;
use App\Models\Fund;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FundResource extends Resource
{
    protected static ?string $model = Fund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Fundos';

    protected static ?string $modelLabel = 'Fundo';

    protected static ?string $pluralModelLabel = 'Fundos';

    protected static ?string $recordTitleAttribute = 'account';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    public static function form(Schema $schema): Schema
    {
        return FundForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundsTable::configure($table);
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
            'fundType',
            'fundName',
            'fundApplication',
            'bank',
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
        return auth()->user()?->can('funds.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFunds::route('/'),
            'create' => CreateFund::route('/create'),
            'edit' => EditFund::route('/{record}/edit'),
        ];
    }
}
