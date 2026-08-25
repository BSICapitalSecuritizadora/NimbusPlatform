<?php

namespace App\Filament\Resources\ImportRuns;

use App\Enums\AccessPermission;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\ImportRunChangesRelationManager;
use App\Filament\Resources\ImportRuns\Schemas\ImportRunInfolist;
use App\Filament\Resources\ImportRuns\Tables\ImportRunsTable;
use App\Models\ImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The audit trail over the monthly reconciliations: who processed which file,
 * when, and what that execution moved.
 *
 * Strictly read-only. An `ImportRun` is a historical fact -- editing or deleting
 * one would be rewriting the record of a conciliation that already happened --
 * so every write path is closed here, not just hidden from the navigation.
 *
 * The counters are the ones the execution persisted and are never recomputed
 * against today's contracts and installments: a later import moving the same
 * rows again does not change what this run did.
 */
class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Auditoria';

    protected static ?int $navigationSort = 24;

    protected static ?string $navigationLabel = 'Histórico de Importações';

    protected static ?string $modelLabel = 'importação';

    protected static ?string $pluralModelLabel = 'Histórico de Importações';

    protected static ?string $recordTitleAttribute = 'file_name';

    public static function infolist(Schema $schema): Schema
    {
        return ImportRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportRunsTable::configure($table);
    }

    /**
     * The user is rendered on every row, so it comes along with the page instead
     * of one query per line. The contract only shows on the detail page.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    /**
     * The individual changes the execution produced, correlated by the activity
     * log batch it opened.
     */
    public static function getRelations(): array
    {
        return [
            ImportRunChangesRelationManager::class,
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(AccessPermission::AuditImportRunsView->value) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view' => ViewImportRun::route('/{record}'),
        ];
    }
}
