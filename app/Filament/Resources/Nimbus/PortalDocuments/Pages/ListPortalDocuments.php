<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Pages;

use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPortalDocuments extends ListRecords
{
    protected static string $resource = PortalDocumentResource::class;

    protected static ?string $title = 'Documentos por Usuário';

    protected static ?string $breadcrumb = 'Listar';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo documento do usuário'),
        ];
    }
}
