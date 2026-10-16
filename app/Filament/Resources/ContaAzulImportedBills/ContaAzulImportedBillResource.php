<?php

namespace App\Filament\Resources\ContaAzulImportedBills;

use App\Filament\Resources\ContaAzulImportedBills\Pages\ListContaAzulImportedBills;
use App\Filament\Resources\ContaAzulImportedBills\Pages\ViewContaAzulImportedBill;
use App\Filament\Resources\ContaAzulImportedBills\RelationManagers\AttributionsRelationManager;
use App\Filament\Resources\ContaAzulImportedBills\Schemas\ContaAzulImportedBillInfolist;
use App\Filament\Resources\ContaAzulImportedBills\Tables\ContaAzulImportedBillsTable;
use App\Models\ContaAzulImportedBill;
use App\Models\ExpenseHistory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ContaAzulImportedBillResource extends Resource
{
    protected static ?string $model = ContaAzulImportedBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Conciliação Conta Azul';

    protected static ?string $modelLabel = 'Título importado';

    protected static ?string $pluralModelLabel = 'Conciliação Conta Azul';

    protected static ?string $recordTitleAttribute = 'description';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 25;

    public static function infolist(Schema $schema): Schema
    {
        return ContaAzulImportedBillInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContaAzulImportedBillsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AttributionsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['bankAccount.bank', 'batch', 'attributedEmission'])
            ->addSelect([
                'has_legacy_history' => ExpenseHistory::query()
                    ->selectRaw('1')
                    ->whereColumn('conta_azul_bill_id', 'conta_azul_imported_bills.conta_azul_bill_id')
                    ->limit(1),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('conta-azul-imports.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('conta-azul-imports.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContaAzulImportedBills::route('/'),
            'view' => ViewContaAzulImportedBill::route('/{record}'),
        ];
    }
}
