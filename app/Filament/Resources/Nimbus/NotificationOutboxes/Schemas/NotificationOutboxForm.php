<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes\Schemas;

use Filament\Schemas\Schema;

class NotificationOutboxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
