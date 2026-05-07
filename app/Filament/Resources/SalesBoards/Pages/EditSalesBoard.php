<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoard;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesBoard extends EditRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Editar quadro de vendas';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation()
            ->modalHeading('Salvar alteracoes do quadro de vendas')
            ->modalDescription('Confirme para salvar as alteracoes. Quando houver mudanca nos valores, o historico anterior sera preservado automaticamente.')
            ->modalSubmitActionLabel('Salvar alteracoes');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (($record instanceof SalesBoard) && $record->hasTrackedValueChanges($data)) {
            $record->snapshotTrackedValues();
        }

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Quadro de vendas atualizado com sucesso.';
    }
}
