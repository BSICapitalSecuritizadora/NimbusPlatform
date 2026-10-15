<?php

namespace App\Filament\Resources\BankApplications\Pages;

use App\Filament\Resources\BankApplications\BankApplicationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateBankApplication extends CreateRecord
{
    protected static string $resource = BankApplicationResource::class;

    protected static ?string $title = 'Criar aplicação bancária';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-form-page bsi-bank-application-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre o produto bancário dentro da sua conta física.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar aplicação')
            ->icon('heroicon-m-plus');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outra')
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
        return 'Aplicação bancária criada com sucesso.';
    }
}
