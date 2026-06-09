<?php

namespace App\Filament\Resources\Negotiations;

use App\Filament\Resources\Negotiations\Pages\CreateNegotiation;
use App\Filament\Resources\Negotiations\Pages\EditNegotiation;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Filament\Resources\Negotiations\Pages\ViewNegotiation;
use App\Filament\Resources\Negotiations\Schemas\NegotiationForm;
use App\Filament\Resources\Negotiations\Tables\NegotiationsTable;
use App\Models\Negotiation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class NegotiationResource extends Resource
{
    protected static ?string $model = Negotiation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Negociações';

    protected static ?string $modelLabel = 'Negociação';

    protected static ?string $pluralModelLabel = 'Negociações';

    protected static ?string $recordTitleAttribute = 'reference_month';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return NegotiationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NegotiationsTable::configure($table);
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
        return auth()->user()?->can('negotiations.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('negotiations.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('negotiations.update') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('negotiations.view') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('negotiations.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNegotiations::route('/'),
            'create' => CreateNegotiation::route('/create'),
            'view' => ViewNegotiation::route('/{record}'),
            'edit' => EditNegotiation::route('/{record}/edit'),
        ];
    }
}
