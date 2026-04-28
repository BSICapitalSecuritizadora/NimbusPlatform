<?php

namespace App\Filament\Resources\ExpenseServiceProviders;

use App\Filament\Resources\ExpenseServiceProviders\Pages\CreateExpenseServiceProvider;
use App\Filament\Resources\ExpenseServiceProviders\Pages\EditExpenseServiceProvider;
use App\Filament\Resources\ExpenseServiceProviders\Pages\ListExpenseServiceProviders;
use App\Filament\Resources\ExpenseServiceProviders\Schemas\ExpenseServiceProviderForm;
use App\Filament\Resources\ExpenseServiceProviders\Tables\ExpenseServiceProvidersTable;
use App\Models\ExpenseServiceProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExpenseServiceProviderResource extends Resource
{
    protected static ?string $model = ExpenseServiceProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Prestadores de serviço';

    protected static ?string $modelLabel = 'Prestador de serviço';

    protected static ?string $pluralModelLabel = 'Prestadores de serviço';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?string $navigationParentItem = 'Despesas';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return ExpenseServiceProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseServiceProvidersTable::configure($table);
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
            ->with('type')
            ->withCount('expenses');
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
            && (! $record->expenses()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseServiceProviders::route('/'),
            'create' => CreateExpenseServiceProvider::route('/create'),
            'edit' => EditExpenseServiceProvider::route('/{record}/edit'),
        ];
    }
}
