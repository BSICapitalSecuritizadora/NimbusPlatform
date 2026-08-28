<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Pages;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewNotificationOutbox extends ViewRecord
{
    protected static string $resource = NotificationOutboxResource::class;

    protected static ?string $title = 'Detalhes do Envio';

    protected static ?string $breadcrumb = 'Detalhes';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-notification-outbox-view-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Histórico técnico e rastreabilidade detalhada do envio de notificação.';
    }

    protected function getHeaderActions(): array
    {
        return [
            NotificationOutboxResource::getCancelAction(),
            NotificationOutboxResource::getReprocessAction(),
        ];
    }
}
