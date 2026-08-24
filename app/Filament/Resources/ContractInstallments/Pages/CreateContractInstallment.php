<?php

namespace App\Filament\Resources\ContractInstallments\Pages;

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContractInstallment extends CreateRecord
{
    protected static string $resource = ContractInstallmentResource::class;

    protected static ?string $title = 'Nova Parcela';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
