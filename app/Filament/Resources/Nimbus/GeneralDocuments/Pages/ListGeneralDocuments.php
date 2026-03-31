<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments\Pages;

use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGeneralDocuments extends ListRecords
{
    protected static string $resource = GeneralDocumentResource::class;

    protected static ?string $title = 'Biblioteca Geral';

    protected static ?string $breadcrumb = 'Listar';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo documento geral'),
        ];
    }
}
