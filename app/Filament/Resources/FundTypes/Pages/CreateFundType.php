<?php

namespace App\Filament\Resources\FundTypes\Pages;

use App\Filament\Resources\FundTypes\FundTypeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateFundType extends CreateRecord
{
    protected static string $resource = FundTypeResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Criar tipo de fundo';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-fund-form-page bsi-simple-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre uma classificação para organizar os fundos das operações.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar tipo de fundo')
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
        return 'Tipo de fundo cadastrado com sucesso.';
    }
}
