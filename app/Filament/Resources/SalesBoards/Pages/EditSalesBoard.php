<?php

namespace App\Filament\Resources\SalesBoards\Pages;

use App\Filament\Resources\SalesBoards\SalesBoardResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSalesBoard extends EditRecord
{
    protected static string $resource = SalesBoardResource::class;

    protected static ?string $title = 'Editar Quadro de Vendas';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar'),
            DeleteAction::make()
                ->label('Excluir Quadro')
                ->modalHeading('Excluir Quadro de Vendas'),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getAddToHistoryFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation()
            ->modalHeading('Salvar alterações do quadro de vendas')
            ->modalDescription('Confirme para salvar as alterações realizadas.')
            ->modalSubmitActionLabel('Salvar alterações');
    }

    protected function getAddToHistoryFormAction(): Action
    {
        return Action::make('addToHistory')
            ->label('Adicionar ao Histórico')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Adicionar valores ao histórico')
            ->modalDescription('Tem certeza de que deseja adicionar os valores atuais ao histórico? Após a confirmação, este registro não poderá mais ser removido.')
            ->modalSubmitActionLabel('Adicionar ao histórico')
            ->action(function (): void {
                $this->saveAndAddToHistory();
            });
    }

    public function saveAndAddToHistory(): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        $this->getRecord()
            ->refresh()
            ->snapshotTrackedValues();

        Notification::make()
            ->success()
            ->title('Valores adicionados ao histórico com sucesso.')
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Quadro de vendas atualizado com sucesso.';
    }
}
