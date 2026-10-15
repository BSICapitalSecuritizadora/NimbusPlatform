<?php

namespace App\Filament\Resources\BankAccounts;

use App\Filament\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Resources\BankAccounts\Pages\ViewBankAccount;
use App\Filament\Resources\BankAccounts\RelationManagers\BankAccountApplicationsRelationManager;
use App\Filament\Resources\BankAccounts\RelationManagers\BankAccountFundsRelationManager;
use App\Filament\Resources\BankAccounts\Schemas\BankAccountForm;
use App\Filament\Resources\BankAccounts\Schemas\BankAccountInfolist;
use App\Filament\Resources\BankAccounts\Tables\BankAccountsTable;
use App\Models\BankAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Contas Bancárias';

    protected static ?string $modelLabel = 'Conta Bancária';

    protected static ?string $pluralModelLabel = 'Contas Bancárias';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Fundos';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return BankAccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BankAccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BankAccountApplicationsRelationManager::class,
            BankAccountFundsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'bank',
        ])->withCount([
            'bankApplications',
            'funds',
        ]);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof BankAccount) {
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

        if (! $record instanceof BankAccount) {
            return false;
        }

        return ! $record->bankApplications()->exists()
            && ! (clone $record->linkedFundQuery())->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
            'view' => ViewBankAccount::route('/{record}'),
            'edit' => EditBankAccount::route('/{record}/edit'),
        ];
    }
}
