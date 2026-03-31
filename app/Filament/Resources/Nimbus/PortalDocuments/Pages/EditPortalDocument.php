<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Pages;

use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditPortalDocument extends EditRecord
{
    protected static string $resource = PortalDocumentResource::class;

    protected static ?string $title = 'Editar Documento do Usuário';

    protected static ?string $breadcrumb = 'Editar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
