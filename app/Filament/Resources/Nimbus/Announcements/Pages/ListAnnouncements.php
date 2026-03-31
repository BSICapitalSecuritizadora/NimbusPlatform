<?php

namespace App\Filament\Resources\Nimbus\Announcements\Pages;

use App\Filament\Resources\Nimbus\Announcements\AnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected static ?string $title = 'Avisos Gerais';

    protected static ?string $breadcrumb = 'Listar';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo aviso'),
        ];
    }
}
