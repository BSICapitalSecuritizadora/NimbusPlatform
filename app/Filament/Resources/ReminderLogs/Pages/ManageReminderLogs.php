<?php

namespace App\Filament\Resources\ReminderLogs\Pages;

use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use Filament\Resources\Pages\ManageRecords;

class ManageReminderLogs extends ManageRecords
{
    protected static string $resource = ReminderLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
