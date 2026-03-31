<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments;

use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\CreateGeneralDocument;
use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\EditGeneralDocument;
use App\Filament\Resources\Nimbus\GeneralDocuments\Pages\ListGeneralDocuments;
use App\Filament\Resources\Nimbus\GeneralDocuments\Schemas\GeneralDocumentForm;
use App\Filament\Resources\Nimbus\GeneralDocuments\Tables\GeneralDocumentsTable;
use App\Models\Nimbus\GeneralDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GeneralDocumentResource extends Resource
{
    protected static ?string $model = GeneralDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Gestão Documental';

    protected static ?string $navigationLabel = 'Biblioteca Geral';

    protected static ?string $modelLabel = 'documento geral';

    protected static ?string $pluralModelLabel = 'Biblioteca Geral';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return GeneralDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GeneralDocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category', 'createdBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGeneralDocuments::route('/'),
            'create' => CreateGeneralDocument::route('/create'),
            'edit' => EditGeneralDocument::route('/{record}/edit'),
        ];
    }
}
