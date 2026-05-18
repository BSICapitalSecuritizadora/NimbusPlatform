<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditEmission extends EditRecord
{
    protected static string $resource = EmissionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
