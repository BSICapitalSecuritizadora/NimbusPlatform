<?php

namespace App\Filament\Resources\SalesBoards;

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Filament\Resources\SalesBoards\RelationManagers\SalesBoardHistoriesRelationManager;
use App\Filament\Resources\SalesBoards\Schemas\SalesBoardForm;
use App\Filament\Resources\SalesBoards\Schemas\SalesBoardInfolist;
use App\Filament\Resources\SalesBoards\Tables\SalesBoardsTable;
use App\Models\SalesBoard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
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

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $navigationParentItem = 'Emissões';

    protected static ?int $navigationSort = 10;

    /**
     * Breadcrumb e títulos mostram a competência como a operação a lê -- `08/2026`
     * e o empreendimento --, nunca a data crua da coluna.
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof SalesBoard) {
            return static::getModelLabel();
        }

        return collect([
            SalesBoard::formatReferenceMonthForDisplay($record->reference_month),
            $record->construction?->development_name,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' · ');
    }

    public static function form(Schema $schema): Schema
    {
        return SalesBoardForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesBoardInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesBoardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalesBoardHistoriesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'emission',
            'construction',
            'initialPosition',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('sales-boards.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('sales-boards.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('sales-boards.update') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('sales-boards.view') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('sales-boards.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesBoards::route('/'),
            'create' => CreateSalesBoard::route('/create'),
            'view' => ViewSalesBoard::route('/{record}'),
        ];
    }
}
