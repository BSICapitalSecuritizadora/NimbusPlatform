<?php

namespace App\Filament\Resources\Negotiations\Pages;

use App\Filament\Resources\Negotiations\NegotiationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateNegotiation extends CreateRecord
{
    protected static string $resource = NegotiationResource::class;

    protected static ?string $title = 'Cadastrar Negociação';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-fund-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Registre as movimentações comerciais mensais de uma operação e empreendimento.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Cadastrar negociação')
            ->icon('heroicon-m-check')
            ->color('primary');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Negociação cadastrada com sucesso.';
    }
}
