<?php

namespace App\Filament\Resources\ContractInstallments\Pages;

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Models\ContractInstallment;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
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

    /**
     * O aviso de competência já registrada, apurado contra a parcela como
     * estava antes de salvar. Vive só durante o salvamento.
     */
    private ?string $registeredCompetenceNotice = null;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var ContractInstallment $installment */
        $installment = $this->getRecord();

        $this->registeredCompetenceNotice = SourceEntryCompetenceNotice::forInstallment(
            $installment->contract()->value('construction_id'),
            array_key_exists('payment_date', $data) ? $data['payment_date'] : $installment->payment_date,
            array_key_exists('cancellation_date', $data) ? $data['cancellation_date'] : $installment->cancellation_date,
            $installment,
        );

        return $data;
    }

    protected function afterSave(): void
    {
        SourceEntryCompetenceNotice::notify($this->registeredCompetenceNotice);

        $this->registeredCompetenceNotice = null;
    }
}
