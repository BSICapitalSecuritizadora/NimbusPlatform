<?php

namespace App\Filament\Resources\ResponsibilityDelegations;

use App\Filament\Resources\ResponsibilityDelegations\Pages\CreateResponsibilityDelegation;
use App\Filament\Resources\ResponsibilityDelegations\Pages\ListResponsibilityDelegations;
use App\Filament\Resources\ResponsibilityDelegations\Pages\ViewResponsibilityDelegation;
use App\Filament\Resources\ResponsibilityDelegations\Schemas\ResponsibilityDelegationForm;
use App\Filament\Resources\ResponsibilityDelegations\Schemas\ResponsibilityDelegationInfolist;
use App\Filament\Resources\ResponsibilityDelegations\Tables\ResponsibilityDelegationsTable;
use App\Models\ResponsibilityDelegation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class ResponsibilityDelegationResource extends Resource
{
    protected static ?string $model = ResponsibilityDelegation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Delegações';

    protected static ?string $modelLabel = 'Delegação';

    protected static ?string $pluralModelLabel = 'Delegações';

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Operações de Obra';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return ResponsibilityDelegationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResponsibilityDelegationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResponsibilityDelegationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResponsibilityDelegations::route('/'),
            'create' => CreateResponsibilityDelegation::route('/create'),
            'view' => ViewResponsibilityDelegation::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['delegator', 'delegate', 'scopeOperation']);

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['super-admin', 'admin']) || $user->can('delegations.manage')) {
            return $query;
        }

        if (! $user->can('delegations.view')) {
            return $query->whereRaw('1 = 0');
        }

        // Regular viewers see only their own delegations (as delegator or delegate).
        return $query->where(function (Builder $q) use ($user) {
            $q->where('delegator_user_id', $user->getKey())
                ->orWhere('delegate_user_id', $user->getKey());
        });
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', ResponsibilityDelegation::class);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('view', $record);
    }

    public static function canCreate(): bool
    {
        return Gate::allows('create', ResponsibilityDelegation::class);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
