<?php

namespace App\Filament\Resources\ReminderLogs\Pages;

use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use Filament\Resources\Pages\ManageRecords;

class ManageReminderLogs extends ManageRecords
{
    protected static string $resource = ReminderLogResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-reminder-logs-list-page',
    ];

    public function getTitle(): string
    {
        return 'Auditoria de lembretes';
    }

    public function getSubheading(): ?string
    {
        return 'Acompanhe os lembretes processados, seus destinatários, canais e resultados de envio.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
