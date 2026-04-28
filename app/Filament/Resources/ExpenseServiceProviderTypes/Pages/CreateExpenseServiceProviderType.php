<?php

namespace App\Filament\Resources\ExpenseServiceProviderTypes\Pages;

use App\Filament\Resources\ExpenseServiceProviderTypes\ExpenseServiceProviderTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseServiceProviderType extends CreateRecord
{
    protected static string $resource = ExpenseServiceProviderTypeResource::class;

    protected static ?string $title = 'Cadastrar tipo de prestador de serviço';

    protected static ?string $breadcrumb = 'Criar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Tipo de prestador de serviço criado com sucesso.';
    }
}
