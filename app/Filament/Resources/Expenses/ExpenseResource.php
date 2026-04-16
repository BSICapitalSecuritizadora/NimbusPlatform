<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Despesas';

    protected static ?string $modelLabel = 'Despesa';

    protected static ?string $pluralModelLabel = 'Despesas';

    protected static ?string $recordTitleAttribute = 'category';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
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
            ->with(['emission', 'serviceProvider']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        if (($data['period'] ?? null) === Expense::PERIOD_SINGLE) {
            $data['end_date'] = null;
        }

        return $data;
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
        return auth()->user()?->can('emissions.update') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
