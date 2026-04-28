<?php

namespace App\Filament\Resources\Constructions\Pages;

use App\Filament\Resources\Constructions\ConstructionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConstruction extends CreateRecord
{
    protected static string $resource = ConstructionResource::class;

    protected static ?string $title = 'Cadastrar obra';

    protected static ?string $breadcrumb = 'Criar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Obra criada com sucesso.';
    }
}
