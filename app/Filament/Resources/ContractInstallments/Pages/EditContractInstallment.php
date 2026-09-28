<?php

namespace App\Filament\Resources\ContractInstallments\Pages;

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditContractInstallment extends EditRecord
{
    protected static string $resource = ContractInstallmentResource::class;

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getTitle(): string
    {
        return 'Parcela '.$this->getRecord()->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            /**
             * Autorizadas pela policy da parcela. Sem a permissão, ou com a
             * parcela no estado errado, a ação some; com o contrato já congelado
             * num ciclo, ela aparece desabilitada e o tooltip diz por quê.
             */
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir parcela')
                ->modalDescription('Use a exclusão apenas para um registro criado por engano. Para tirar uma parcela do fluxo contratual preservando o histórico, informe a data de cancelamento.')
                ->authorizationTooltip(),

            RestoreAction::make()
                ->label('Restaurar')
                ->authorizationTooltip(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
