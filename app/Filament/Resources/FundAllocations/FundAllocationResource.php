<?php

namespace App\Filament\Resources\FundAllocations;

use App\Filament\Resources\FundAllocations\Pages\CreateFundAllocation;
use App\Filament\Resources\FundAllocations\Pages\ListFundAllocations;
use App\Filament\Resources\FundAllocations\Pages\ViewFundAllocation;
use App\Filament\Resources\FundAllocations\RelationManagers\FundAllocationRevisionsRelationManager;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationForm;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationInfolist;
use App\Filament\Resources\FundAllocations\Tables\FundAllocationsTable;
use App\Models\FundAllocationPeriod;
use App\Services\Funds\FundAllocationService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FundAllocationResource extends Resource
{
    protected static ?string $model = FundAllocationPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Alocações por Competência';

    protected static ?string $modelLabel = 'Alocação por competência';

    protected static ?string $pluralModelLabel = 'Alocações por competência';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Fundos';

    protected static ?int $navigationSort = 17;

    public static function form(Schema $schema): Schema
    {
        return FundAllocationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FundAllocationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundAllocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FundAllocationRevisionsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'bankApplication.bankAccount.bank',
            'openRevision.proposedBy',
            'openRevision.submittedBy',
            'effectiveRevision.approvedBy',
        ]);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof FundAllocationPeriod) {
            return null;
        }

        $competence = $record->competence?->format('m/Y') ?? '—';
        $application = $record->bankApplication?->optionLabel() ?? "aplicação #{$record->bank_application_id}";

        return "{$application} · {$competence}";
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(FundAllocationService::PERMISSION_VIEW) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can(FundAllocationService::PERMISSION_VIEW) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(FundAllocationService::PERMISSION_CREATE) ?? false;
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
            'index' => ListFundAllocations::route('/'),
            'create' => CreateFundAllocation::route('/create'),
            'view' => ViewFundAllocation::route('/{record}'),
        ];
    }
}
