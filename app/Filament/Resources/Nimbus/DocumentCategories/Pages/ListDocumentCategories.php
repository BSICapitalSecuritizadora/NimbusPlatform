<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Pages;

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentCategories extends ListRecords
{
    protected static string $resource = DocumentCategoryResource::class;

    protected static ?string $title = 'Categorias de Documentos';

    protected static ?string $breadcrumb = 'Listar';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova categoria'),
        ];
    }
}
