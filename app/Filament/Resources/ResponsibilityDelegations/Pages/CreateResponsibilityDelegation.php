<?php

namespace App\Filament\Resources\ResponsibilityDelegations\Pages;

use App\Filament\Resources\ResponsibilityDelegations\ResponsibilityDelegationResource;
use App\Services\ResponsibilityDelegationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateResponsibilityDelegation extends CreateRecord
{
    protected static string $resource = ResponsibilityDelegationResource::class;

    protected static ?string $title = 'Nova Delegação';

    protected function handleRecordCreation(array $data): Model
    {
        return app(ResponsibilityDelegationService::class)->createDelegation($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return ResponsibilityDelegationResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Delegação criada com sucesso';
    }
}
