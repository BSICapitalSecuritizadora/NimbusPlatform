<?php

namespace App\Filament\Resources\Receivables;

use App\Filament\Resources\Receivables\Pages\CreateReceivable;
use App\Filament\Resources\Receivables\Pages\EditReceivable;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Schemas\ReceivableForm;
use App\Filament\Resources\Receivables\Tables\ReceivablesTable;
use App\Models\Receivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Recebíveis';

    protected static ?string $modelLabel = 'Resumo de recebíveis';

    protected static ?string $pluralModelLabel = 'Resumos de recebíveis';

    protected static ?string $recordTitleAttribute = 'reference_month';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return ReceivableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('emission');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('receivables.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('receivables.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('receivables.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('receivables.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
            'create' => CreateReceivable::route('/create'),
            'edit' => EditReceivable::route('/{record}/edit'),
        ];
    }
}
