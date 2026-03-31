<?php

namespace App\Filament\Resources\Nimbus\Announcements\Pages;

use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected static ?string $title = 'Novo Aviso Geral';

    protected static ?string $breadcrumb = 'Criar';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
