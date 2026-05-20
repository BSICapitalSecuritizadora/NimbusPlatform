<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateEmission extends CreateRecord
{
    protected static string $resource = EmissionResource::class;

    protected static ?string $title = 'Cadastrar Emissão';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Emissão cadastrada com sucesso.';
    }
}
