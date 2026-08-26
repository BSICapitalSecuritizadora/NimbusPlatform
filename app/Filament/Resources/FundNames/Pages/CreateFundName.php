<?php

namespace App\Filament\Resources\FundNames\Pages;

use App\Filament\Resources\FundNames\FundNameResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateFundName extends CreateRecord
{
    protected static string $resource = FundNameResource::class;

    protected static ?string $title = 'Criar nome de fundo';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-form-page bsi-fund-form-page bsi-fund-name-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre uma nova denominação para vinculá-la a um tipo de fundo.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar nome de fundo')
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
        return 'Nome de fundo criado com sucesso.';
    }
}
