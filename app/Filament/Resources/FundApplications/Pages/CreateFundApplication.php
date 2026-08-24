<?php

namespace App\Filament\Resources\FundApplications\Pages;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateFundApplication extends CreateRecord
{
    protected static string $resource = FundApplicationResource::class;

    protected static ?string $title = 'Criar aplicação';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-simple-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre uma aplicação para utilizá-la na configuração financeira dos fundos.';
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
        return 'Aplicação cadastrada com sucesso.';
    }
}
