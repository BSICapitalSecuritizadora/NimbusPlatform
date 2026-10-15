<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateBankAccount extends CreateRecord
{
    protected static string $resource = BankAccountResource::class;

    protected static ?string $title = 'Criar conta bancária';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-form-page bsi-bank-account-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre a identificação canônica da conta física e sua configuração de integração.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar conta')
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
        return 'Conta bancária criada com sucesso.';
    }
}
