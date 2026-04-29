<?php

namespace App\Filament\Resources\SalesBoards;

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\EditSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Resources\SalesBoards\Schemas\SalesBoardForm;
use App\Filament\Resources\SalesBoards\Tables\SalesBoardsTable;
use App\Models\SalesBoard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SalesBoardResource extends Resource
{
    protected static ?string $model = SalesBoard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Quadro de Vendas';

    protected static ?string $modelLabel = 'Quadro de Vendas';

    protected static ?string $pluralModelLabel = 'Quadros de Vendas';

    protected static ?string $recordTitleAttribute = 'reference_month';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return SalesBoardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesBoardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'emission',
            'construction',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('emissions.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('emissions.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('emissions.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('emissions.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesBoards::route('/'),
            'create' => CreateSalesBoard::route('/create'),
            'edit' => EditSalesBoard::route('/{record}/edit'),
        ];
    }
}
