<?php

namespace App\Filament\Resources\ContractInstallments\Pages;

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Models\ContractInstallment;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use Filament\Resources\Pages\CreateRecord;

class CreateContractInstallment extends CreateRecord
{
    protected static string $resource = ContractInstallmentResource::class;

    protected static ?string $title = 'Nova Parcela';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * O pagamento ou o cancelamento com data em competência já registrada no
     * Quadro de Vendas: notificação persistente depois de salvar.
     */
    protected function afterCreate(): void
    {
        /** @var ContractInstallment $installment */
        $installment = $this->getRecord();

        SourceEntryCompetenceNotice::notify(SourceEntryCompetenceNotice::forInstallment(
            $installment->contract()->value('construction_id'),
            $installment->payment_date,
            $installment->cancellation_date,
        ));
    }
}
