<?php

namespace App\Filament\Resources\Nimbus\PortalUsers\Pages;

use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class CreatePortalUser extends CreateRecord
{
    protected static string $resource = PortalUserResource::class;

    protected static ?string $title = 'Novo Usuário';

    protected static ?string $breadcrumb = 'Criar';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-portal-user-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre um usuário externo e configure suas informações de acesso ao portal.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar usuário')
            ->icon(Heroicon::OutlinedPlus)
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
}
