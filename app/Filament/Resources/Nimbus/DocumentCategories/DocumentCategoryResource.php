<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories;

use App\Filament\Resources\Nimbus\DocumentCategories\Pages\CreateDocumentCategory;
use App\Filament\Resources\Nimbus\DocumentCategories\Pages\EditDocumentCategory;
use App\Filament\Resources\Nimbus\DocumentCategories\Pages\ListDocumentCategories;
use App\Filament\Resources\Nimbus\DocumentCategories\Schemas\DocumentCategoryForm;
use App\Filament\Resources\Nimbus\DocumentCategories\Tables\DocumentCategoriesTable;
use App\Models\Nimbus\DocumentCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentCategoryResource extends Resource
{
    protected static ?string $model = DocumentCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Gestão Documental';

    protected static ?string $navigationLabel = 'Categorias de Documentos';

    protected static ?string $modelLabel = 'categoria de documento';

    protected static ?string $pluralModelLabel = 'Categorias de Documentos';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return DocumentCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentCategoriesTable::configure($table);
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
            'index' => ListDocumentCategories::route('/'),
            'create' => CreateDocumentCategory::route('/create'),
            'edit' => EditDocumentCategory::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('nimbus.document-categories.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('nimbus.document-categories.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('nimbus.document-categories.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('nimbus.document-categories.delete') ?? false;
    }
}
