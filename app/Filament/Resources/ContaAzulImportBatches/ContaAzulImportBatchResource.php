<?php

namespace App\Filament\Resources\ContaAzulImportBatches;

use App\Filament\Resources\ContaAzulImportBatches\Pages\ListContaAzulImportBatches;
use App\Filament\Resources\ContaAzulImportBatches\Pages\ViewContaAzulImportBatch;
use App\Filament\Resources\ContaAzulImportBatches\RelationManagers\ObservedBillsRelationManager;
use App\Filament\Resources\ContaAzulImportBatches\Schemas\ContaAzulImportBatchInfolist;
use App\Filament\Resources\ContaAzulImportBatches\Tables\ContaAzulImportBatchesTable;
use App\Models\ContaAzulImportBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ContaAzulImportBatchResource extends Resource
{
    protected static ?string $model = ContaAzulImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'Importações Conta Azul';

    protected static ?string $modelLabel = 'Importação';

    protected static ?string $pluralModelLabel = 'Importações Conta Azul';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 26;

    public static function infolist(Schema $schema): Schema
    {
        return ContaAzulImportBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContaAzulImportBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ObservedBillsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['bankAccount.bank', 'requestedBy', 'recoveredBy']);
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
            'index' => ListContaAzulImportBatches::route('/'),
            'view' => ViewContaAzulImportBatch::route('/{record}'),
        ];
    }
}
