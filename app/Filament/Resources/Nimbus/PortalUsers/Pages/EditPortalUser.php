<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Pages;

use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPortalUser extends EditRecord
{
    protected static string $resource = PortalUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
