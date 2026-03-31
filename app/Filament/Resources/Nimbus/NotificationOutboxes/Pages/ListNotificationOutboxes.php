<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Pages;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListNotificationOutboxes extends ListRecords
{
    protected static string $resource = NotificationOutboxResource::class;

    protected static ?string $title = 'Auditoria de Envios';

    protected static ?string $breadcrumb = 'Listar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
