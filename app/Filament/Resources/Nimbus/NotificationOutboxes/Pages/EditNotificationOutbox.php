<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Pages;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNotificationOutbox extends EditRecord
{
    protected static string $resource = NotificationOutboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
