<?php

namespace App\Filament\Resources\Nimbus\PortalDocuments\Pages;

use App\Filament\Resources\Nimbus\PortalDocuments\PortalDocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreatePortalDocument extends CreateRecord
{
    protected static string $resource = PortalDocumentResource::class;

    protected static ?string $title = 'Novo Documento do Usuário';

    protected static ?string $breadcrumb = 'Criar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
