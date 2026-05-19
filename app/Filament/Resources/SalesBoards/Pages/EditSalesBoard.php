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

    protected static ?string $title = 'Editar quadro de vendas';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar'),
            DeleteAction::make(),
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
            ->modalHeading('Salvar alteracoes do quadro de vendas')
            ->modalDescription('Confirme para salvar as alteracoes.')
            ->modalSubmitActionLabel('Salvar alteracoes');
    }

    protected function getAddToHistoryFormAction(): Action
    {
        return Action::make('addToHistory')
            ->label('Adicionar ao historico')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Adicionar valores ao historico')
            ->modalDescription('Tem certeza de que deseja adicionar os valores atuais ao historico? Apos a confirmacao, esse registro nao podera mais ser removido.')
            ->modalSubmitActionLabel('Adicionar ao historico')
            ->action('saveAndAddToHistory');
    }

    public function saveAndAddToHistory(): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        $this->getRecord()
            ->refresh()
            ->snapshotTrackedValues();

        Notification::make()
            ->success()
            ->title('Valores adicionados ao historico com sucesso.')
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Quadro de vendas atualizado com sucesso.';
    }
}
