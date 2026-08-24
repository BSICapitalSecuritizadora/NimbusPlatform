<?php

namespace App\Filament\Resources\ConstructionUnits\Pages;

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConstructionUnit extends CreateRecord
{
    protected static string $resource = ConstructionUnitResource::class;

    protected static ?string $title = 'Cadastrar Unidade';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Unidade cadastrada com sucesso.';
    }
}
