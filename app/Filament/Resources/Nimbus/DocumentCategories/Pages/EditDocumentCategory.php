<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Pages;

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentCategory extends EditRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected static ?string $title = 'Editar Categoria de Documento';

    protected static ?string $breadcrumb = 'Editar';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
