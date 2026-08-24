<?php

namespace App\Filament\Resources\ExpenseServiceProviders\Pages;

use App\Filament\Resources\ExpenseServiceProviders\ExpenseServiceProviderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateExpenseServiceProvider extends CreateRecord
{
    protected static string $resource = ExpenseServiceProviderResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Cadastrar prestador de serviço';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-service-provider-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre uma empresa ou instituição para vincular como prestador nas despesas e operações.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Cadastrar prestador')
            ->icon('heroicon-m-plus');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outro')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Prestador de serviço cadastrado com sucesso.';
    }
}
