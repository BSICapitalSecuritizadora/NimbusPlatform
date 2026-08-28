<?php

namespace App\Filament\Resources\PaymentWorkspaces;

use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Resources\PaymentWorkspaces\Tables\PaymentWorkspaceTable;
use App\Models\Measurement;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentWorkspaceResource extends Resource
{
    protected static ?string $model = Measurement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Workspace de Pagamentos';

    protected static ?string $modelLabel = 'Pagamento operacional';

    protected static ?string $pluralModelLabel = 'Workspace de Pagamentos';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Obras';

    protected static ?int $navigationSort = 23;

    public static function table(Table $table): Table
    {
        return PaymentWorkspaceTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(MeasurementOperationalReadModel::class)->paymentQueryFor($user)
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Measurement::class) ?? false;
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

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentWorkspace::route('/'),
        ];
    }
}
