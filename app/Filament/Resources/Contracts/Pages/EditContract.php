<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Editar Contrato';

    protected static ?string $breadcrumb = 'Editar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    /**
     * O aviso de competência já registrada, apurado contra o contrato como
     * estava antes de salvar. Não é estado do componente: vive só durante o
     * salvamento.
     */
    private ?string $registeredCompetenceNotice = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Visualizar'),
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir contrato')
                ->modalDescription(ContractResource::DELETE_MODAL_DESCRIPTION)
                ->authorizationTooltip(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Contract $contract */
        $contract = $this->getRecord();

        $data['client_ids'] = $contract->buyerIds();

        return $data;
    }

    /**
     * Scalars and buyer set in one transaction, and the buyer change recorded as
     * its own activity: `LogsActivity` only watches columns, so a set that lives
     * in another table would move without leaving a trace otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $buyerIds = Arr::wrap($data['client_ids'] ?? []);

        unset($data['client_ids']);

        /** @var Contract $record */
        $this->registeredCompetenceNotice = SourceEntryCompetenceNotice::forContract(
            $record->construction_id,
            $data['sale_date'] ?? $record->sale_date,
            $data['sale_value'] ?? $record->sale_value,
            array_key_exists('cancellation_date', $data) ? $data['cancellation_date'] : $record->cancellation_date,
            $record,
        );

        return DB::transaction(function () use ($record, $data, $buyerIds): Model {
            $record->update($data);

            /** @var Contract $record */
            $record->syncBuyers($buyerIds);

            return $record;
        });
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Contrato atualizado com sucesso.';
    }

    /**
     * A alteração alcança competência já registrada no Quadro de Vendas: a
     * notificação fica até ser fechada.
     */
    protected function afterSave(): void
    {
        SourceEntryCompetenceNotice::notify($this->registeredCompetenceNotice);

        $this->registeredCompetenceNotice = null;
    }
}
