<?php

namespace App\Filament\Resources\Nimbus\DocumentCategories\Pages;

use App\Filament\Resources\Nimbus\DocumentCategories\DocumentCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentCategory extends CreateRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected static ?string $title = 'Nova Categoria de Documento';

    protected static ?string $breadcrumb = 'Criar';
}
