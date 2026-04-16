<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\EditPayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Pagamentos';

    protected static ?string $modelLabel = 'Pagamento';

    protected static ?string $pluralModelLabel = 'Pagamentos';

    protected static ?string $recordTitleAttribute = 'payment_date';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?string $navigationParentItem = 'Despesas';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('emissions.view');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('emissions.update');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('emissions.update');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('emissions.update');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'create' => CreatePayment::route('/create'),
            'edit' => EditPayment::route('/{record}/edit'),
        ];
    }
}
