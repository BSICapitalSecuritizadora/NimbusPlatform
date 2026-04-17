<?php

namespace App\Filament\Resources\FundApplications;

use App\Filament\Resources\FundApplications\Pages\CreateFundApplication;
use App\Filament\Resources\FundApplications\Pages\EditFundApplication;
use App\Filament\Resources\FundApplications\Pages\ListFundApplications;
use App\Filament\Resources\FundApplications\Schemas\FundApplicationForm;
use App\Filament\Resources\FundApplications\Tables\FundApplicationsTable;
use App\Models\FundApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FundApplicationResource extends Resource
{
    protected static ?string $model = FundApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Aplicações';

    protected static ?string $modelLabel = 'Aplicação';

    protected static ?string $pluralModelLabel = 'Aplicações';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastro';

    protected static ?string $navigationParentItem = 'Fundos';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return FundApplicationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundApplicationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('funds');
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
        return (auth()->user()?->can('funds.delete') ?? false)
            && (! $record->funds()->exists());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundApplications::route('/'),
            'create' => CreateFundApplication::route('/create'),
            'edit' => EditFundApplication::route('/{record}/edit'),
        ];
    }
}
