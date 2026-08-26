<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
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

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Visualizar'),
            DeleteAction::make()
                ->label('Excluir')
                ->modalHeading('Excluir contrato')
                ->modalDescription('O contrato deixa de aparecer na listagem e libera a unidade, mas é preservado para manter o histórico comercial.'),
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
}
