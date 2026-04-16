<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Pages;

use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseServiceProvider extends CreateRecord
{
    protected static string $resource = ExpenseServiceProviderResource::class;

    protected static ?string $title = 'Cadastrar prestador de serviço';

    protected static ?string $breadcrumb = 'Criar';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Prestador de serviço criado com sucesso.';
    }
}
