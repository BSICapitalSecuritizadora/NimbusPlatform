<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    protected static ?string $breadcrumb = 'Visualizar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

    public function getTitle(): string
    {
        return 'Contrato '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->visible(fn (Contract $record): bool => ContractResource::canEdit($record)),
            /**
             * Autorizada pela policy, pela autorização padrão da página, como
             * a exclusão na edição. A recusa com motivo -- contrato congelado
             * num ciclo, ou que voltaria a ocupar a unidade durante uma baixa
             * -- deixa o botão desabilitado com o motivo, em vez de sumir com
             * ele; a falta da permissão continua escondendo.
             */
            RestoreAction::make()
                ->label('Restaurar Contrato')
                ->authorizationTooltip(),
        ];
    }
}
