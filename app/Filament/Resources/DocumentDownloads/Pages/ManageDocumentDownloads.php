<?php

namespace App\Filament\Resources\DocumentDownloads\Pages;

use App\Filament\Resources\DocumentDownloads\DocumentDownloadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentDownloads extends ManageRecords
{
    protected static string $resource = DocumentDownloadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
