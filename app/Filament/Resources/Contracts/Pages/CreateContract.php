<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Support\SalesBoards\SourceEntryCompetenceNotice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Cadastrar Contrato';

    protected static ?string $breadcrumb = 'Cadastrar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    /**
     * The buyers are rows of another table, so the contract and its buyer set
     * are written together or not at all: a contract that committed without its
     * buyers would be a sale with nobody on it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $buyerIds = Arr::wrap($data['client_ids'] ?? []);

        unset($data['client_ids']);

        return DB::transaction(function () use ($data, $buyerIds): Contract {
            /** @var Contract $contract */
            $contract = static::getModel()::create($data);

            $contract->clients()->sync($buyerIds);

            return $contract;
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Contrato cadastrado com sucesso.';
    }

    /**
     * A venda com data em competência já registrada no Quadro de Vendas: o
     * formulário avisou antes, e a notificação fica até ser fechada -- quem
     * salvou precisa saber por onde o fato vai entrar.
     */
    protected function afterCreate(): void
    {
        /** @var Contract $contract */
        $contract = $this->getRecord();

        SourceEntryCompetenceNotice::notify(SourceEntryCompetenceNotice::forContract(
            $contract->construction_id,
            $contract->sale_date,
            $contract->sale_value,
            $contract->cancellation_date,
        ));
    }
}
