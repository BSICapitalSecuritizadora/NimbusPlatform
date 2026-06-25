<?php

namespace App\Filament\Resources\EmissionMonthlyReportNotes;

use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\CreateEmissionMonthlyReportNote;
use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\EditEmissionMonthlyReportNote;
use App\Filament\Resources\EmissionMonthlyReportNotes\Pages\ListEmissionMonthlyReportNotes;
use App\Filament\Resources\EmissionMonthlyReportNotes\Schemas\EmissionMonthlyReportNoteForm;
use App\Filament\Resources\EmissionMonthlyReportNotes\Tables\EmissionMonthlyReportNotesTable;
use App\Models\EmissionMonthlyReportNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class EmissionMonthlyReportNoteResource extends Resource
{
    protected static ?string $model = EmissionMonthlyReportNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Comentários do Relatório';

    protected static ?string $modelLabel = 'Comentário do Relatório';

    protected static ?string $pluralModelLabel = 'Comentários do Relatório';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?int $navigationSort = 41;

    public static function form(Schema $schema): Schema
    {
        return EmissionMonthlyReportNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmissionMonthlyReportNotesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'emission',
            'createdBy',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('reports.comments.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('reports.comments.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('reports.comments.update') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('reports.comments.view') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('reports.comments.delete') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmissionMonthlyReportNotes::route('/'),
            'create' => CreateEmissionMonthlyReportNote::route('/create'),
            'edit' => EditEmissionMonthlyReportNote::route('/{record}/edit'),
        ];
    }
}
