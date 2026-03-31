<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Pages;

use App\Filament\Resources\Nimbus\NotificationOutboxes\NotificationOutboxResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationOutbox extends CreateRecord
{
    protected static string $resource = NotificationOutboxResource::class;
}
