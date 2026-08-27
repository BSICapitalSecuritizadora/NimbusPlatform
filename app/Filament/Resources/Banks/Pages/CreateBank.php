<?php

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateBank extends CreateRecord
{
    protected static string $resource = BankResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Criar banco';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-form-page bsi-bank-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function getSubheading(): ?string
    {
        return 'Cadastre uma nova instituição bancária com nome e logotipo institucional.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar banco')
            ->icon('heroicon-m-plus')
            ->color('primary');
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
        return 'Banco cadastrado com sucesso.';
    }
}
