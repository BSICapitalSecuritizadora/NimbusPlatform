<?php

namespace App\Filament\Resources\Contracts;

use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Models\Contract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Contratos';

    protected static ?string $modelLabel = 'Contrato';

    protected static ?string $pluralModelLabel = 'Contratos';

    protected static ?string $recordTitleAttribute = 'code';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContractInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    /**
     * Every column of the listing reads through a relation, so they are all
     * loaded up front: without this the table costs four queries per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'client',
                'constructionUnit',
                'construction.emission',
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'client.name', 'client.document', 'constructionUnit.unit', 'construction.development_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->code;
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Cliente' => $record->client?->name ?? '—',
            'Unidade' => $record->constructionUnit?->display_name ?? '—',
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('contracts.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('contracts.create') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('contracts.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('contracts.update') ?? false)
            && ! ($record instanceof Contract && $record->trashed());
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can('contracts.delete') ?? false)
            && ! ($record instanceof Contract && $record->trashed());
    }

    public static function canRestore(Model $record): bool
    {
        return (auth()->user()?->can('contracts.restore') ?? false)
            && ($record instanceof Contract)
            && $record->trashed();
    }

    /**
     * A contract is commercial history: erasing it for good would take the sale,
     * the distrato and the trail of a unit with it.
     */
    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            ContractInstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'create' => CreateContract::route('/create'),
            'view' => ViewContract::route('/{record}'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }
}
