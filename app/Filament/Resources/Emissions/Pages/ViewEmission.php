<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmission extends ViewRecord
{
    protected static string $resource = EmissionResource::class;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
