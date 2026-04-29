<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments;

use App\Filament\Resources\Nimbus\PortalDocuments\Pages\CreatePortalDocument;
use App\Filament\Resources\Nimbus\PortalDocuments\Pages\EditPortalDocument;
use App\Filament\Resources\Nimbus\PortalDocuments\Pages\ListPortalDocuments;
use App\Filament\Resources\Nimbus\PortalDocuments\Schemas\PortalDocumentForm;
use App\Filament\Resources\Nimbus\PortalDocuments\Tables\PortalDocumentsTable;
use App\Models\Nimbus\PortalDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PortalDocumentResource extends Resource
{
    protected static ?string $model = PortalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Gestão Documental';

    protected static ?string $navigationLabel = 'Documentos por Usuário';

    protected static ?string $modelLabel = 'documento por usuário';

    protected static ?string $pluralModelLabel = 'Documentos por Usuário';

    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return PortalDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PortalDocumentsTable::configure($table);
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
            ->with(['portalUser', 'createdBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPortalDocuments::route('/'),
            'create' => CreatePortalDocument::route('/create'),
            'edit' => EditPortalDocument::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('nimbus.portal-documents.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('nimbus.portal-documents.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('nimbus.portal-documents.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('nimbus.portal-documents.delete') ?? false;
    }
}
