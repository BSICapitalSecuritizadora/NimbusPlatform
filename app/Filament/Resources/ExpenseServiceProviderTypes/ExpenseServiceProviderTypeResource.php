<?php

namespace App\Filament\Resources\ExpenseServiceProviderTypes;

use App\Filament\Resources\ExpenseServiceProviderTypes\Pages\CreateExpenseServiceProviderType;
use App\Filament\Resources\ExpenseServiceProviderTypes\Pages\EditExpenseServiceProviderType;
use App\Filament\Resources\ExpenseServiceProviderTypes\Pages\ListExpenseServiceProviderTypes;
use App\Filament\Resources\ExpenseServiceProviderTypes\Schemas\ExpenseServiceProviderTypeForm;
use App\Filament\Resources\ExpenseServiceProviderTypes\Tables\ExpenseServiceProviderTypesTable;
use App\Models\ExpenseServiceProviderType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExpenseServiceProviderTypeResource extends Resource
{
    protected static ?string $model = ExpenseServiceProviderType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Tipos de prestador de serviço';

    protected static ?string $modelLabel = 'Tipo de prestador de serviço';

    protected static ?string $pluralModelLabel = 'Tipos de prestador de serviço';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?string $navigationParentItem = 'Despesas';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ExpenseServiceProviderTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseServiceProviderTypesTable::configure($table);
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
            ->withCount('serviceProviders');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('emissions.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('emissions.update') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('emissions.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can('emissions.update') ?? false)
            && (! $record->serviceProviders()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseServiceProviderTypes::route('/'),
            'create' => CreateExpenseServiceProviderType::route('/create'),
            'edit' => EditExpenseServiceProviderType::route('/{record}/edit'),
        ];
    }
}
