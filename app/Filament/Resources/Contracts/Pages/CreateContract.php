<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Cadastrar Contrato';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Contrato cadastrado com sucesso.';
    }
}
