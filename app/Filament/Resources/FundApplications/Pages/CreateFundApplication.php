<?php

namespace App\Filament\Resources\FundApplications\Pages;

use App\Filament\Resources\FundApplications\FundApplicationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateFundApplication extends CreateRecord
{
    protected static string $resource = FundApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Criar aplicação';

    protected static ?string $breadcrumb = 'Criar';

    protected ?string $subheading = 'Cadastre uma aplicação para utilizá-la na configuração financeira dos fundos.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-form-page bsi-fund-application-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar aplicação')
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
        return 'Aplicação cadastrada com sucesso.';
    }
}
