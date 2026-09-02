<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Filament\Resources\Operations\OperationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOperation extends ViewRecord
{
    protected static string $resource = OperationResource::class;

    /**
     * A tela de visualização é o lugar natural do ciclo de vida: quem confere
     * uma operação encerrada aqui é quem decide reabri-la, e quem acompanha uma
     * em rascunho é quem decide ativá-la.
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OperationResource::getActivateOperationAction()->record($this->record),
            OperationResource::getCompleteOperationAction()->record($this->record),
            OperationResource::getCancelOperationAction()->record($this->record),
            OperationResource::getReopenOperationAction()->record($this->record),
        ];
    }
}
