<?php

namespace App\Filament\Resources\Nimbus\GeneralDocuments\Pages;

use App\Filament\Resources\Nimbus\GeneralDocuments\GeneralDocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGeneralDocument extends EditRecord
{
    protected static string $resource = GeneralDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
