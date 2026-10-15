<?php

namespace App\Filament\Resources\BankApplications;

use App\Filament\Resources\BankApplications\Pages\CreateBankApplication;
use App\Filament\Resources\BankApplications\Pages\EditBankApplication;
use App\Filament\Resources\BankApplications\Pages\ListBankApplications;
use App\Filament\Resources\BankApplications\Pages\ViewBankApplication;
use App\Filament\Resources\BankApplications\RelationManagers\BankApplicationAllocationsRelationManager;
use App\Filament\Resources\BankApplications\RelationManagers\BankApplicationFundsRelationManager;
use App\Filament\Resources\BankApplications\Schemas\BankApplicationForm;
use App\Filament\Resources\BankApplications\Schemas\BankApplicationInfolist;
use App\Filament\Resources\BankApplications\Tables\BankApplicationsTable;
use App\Models\BankApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BankApplicationResource extends Resource
{
    protected static ?string $model = BankApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Aplicações Bancárias';

    protected static ?string $modelLabel = 'Aplicação Bancária';

    protected static ?string $pluralModelLabel = 'Aplicações Bancárias';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Fundos';

    protected static ?int $navigationSort = 16;

    public static function form(Schema $schema): Schema
    {
        return BankApplicationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BankApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankApplicationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BankApplicationFundsRelationManager::class,
            BankApplicationAllocationsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'bankAccount.bank',
            ])
            ->withSharedEmissionCount()
            ->withCount([
                'funds',
            ]);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof BankApplication) {
            return null;
        }

        return $record->optionLabel();
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
        if (! (auth()->user()?->can('funds.delete') ?? false)) {
            return false;
        }

        if (! $record instanceof BankApplication) {
            return false;
        }

        return ! $record->funds()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankApplications::route('/'),
            'create' => CreateBankApplication::route('/create'),
            'view' => ViewBankApplication::route('/{record}'),
            'edit' => EditBankApplication::route('/{record}/edit'),
        ];
    }
}
