<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\MeasurementsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Filament\Resources\Operations\Schemas\OperationForm;
use App\Filament\Resources\Operations\Schemas\OperationInfolist;
use App\Filament\Resources\Operations\Tables\OperationsTable;
use App\Models\Operation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OperationResource extends Resource
{
    protected static ?string $model = Operation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Operações de Obra';

    protected static ?string $modelLabel = 'Operação de Obra';

    protected static ?string $pluralModelLabel = 'Operações de Obra';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return OperationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OperationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PlanSetsRelationManager::class,
            PlanLinesRelationManager::class,
            MeasurementsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['emission', 'planSets.construction']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('operations.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('operations.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('operations.update') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('operations.view') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('operations.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperations::route('/'),
            'create' => CreateOperation::route('/create'),
            'view' => ViewOperation::route('/{record}'),
            'edit' => EditOperation::route('/{record}/edit'),
        ];
    }
}
