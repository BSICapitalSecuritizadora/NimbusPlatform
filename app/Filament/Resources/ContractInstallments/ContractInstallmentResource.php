<?php

namespace App\Filament\Resources\ContractInstallments;

use App\Filament\Resources\ContractInstallments\Pages\CreateContractInstallment;
use App\Filament\Resources\ContractInstallments\Pages\EditContractInstallment;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\ContractInstallments\Schemas\ContractInstallmentForm;
use App\Filament\Resources\ContractInstallments\Tables\ContractInstallmentsTable;
use App\Filament\Support\AuthorizesThroughModelPolicy;
use App\Models\ContractInstallment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The global listing of installments, next to the one embedded in each
 * contract.
 *
 * It is not a duplicate of the relation manager: the questions it answers are
 * the ones that cross contracts -- every overdue installment, every installment
 * of an emission, everything a client still owes -- and none of those can be
 * asked from inside a single contract's page.
 */
class ContractInstallmentResource extends Resource
{
    use AuthorizesThroughModelPolicy;

    protected static ?string $model = ContractInstallment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Parcelas';

    protected static ?string $modelLabel = 'Parcela';

    protected static ?string $pluralModelLabel = 'Parcelas';

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 32;

    public static function form(Schema $schema): Schema
    {
        return ContractInstallmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractInstallmentsTable::configure($table);
    }

    /**
     * Every column that is not on the installment itself reads through the
     * contract, so the whole chain is loaded up front: without this the listing
     * costs five queries per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'contract.clients',
                'contract.constructionUnit',
                'contract.construction.emission',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContractInstallments::route('/'),
            'create' => CreateContractInstallment::route('/create'),
            'edit' => EditContractInstallment::route('/{record}/edit'),
        ];
    }
}
