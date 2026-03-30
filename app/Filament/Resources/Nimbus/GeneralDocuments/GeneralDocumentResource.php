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

class GeneralDocumentResource extends Resource
{
    protected static ?string $model = GeneralDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

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

    public static function getPages(): array
    {
        return [
            'index' => ListGeneralDocuments::route('/'),
            'create' => CreateGeneralDocument::route('/create'),
            'edit' => EditGeneralDocument::route('/{record}/edit'),
        ];
    }
}
