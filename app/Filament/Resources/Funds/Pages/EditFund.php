<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Filament\Resources\Funds\FundResource;
use App\Models\Fund;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFund extends EditRecord
{
    protected static string $resource = FundResource::class;

    protected static ?string $title = 'Editar fundo';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->getRecord()->requiresMonthlyBalanceUpdate()) {
            Notification::make()
                ->warning()
                ->title('Atualizacao mensal de saldo pendente.')
                ->body('Confirme e salve o saldo deste fundo. O valor do mes anterior sera preservado automaticamente no historico.')
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->getRecord()->snapshotPreviousMonthBalanceIfMissing();
        $data['balance_updated_at'] = now();

        return $data;
    }

    protected function afterSave(): void
    {
        if (! ($this->getRecord() instanceof Fund)) {
            return;
        }

        if (! $this->getRecord()->isBalanceBelowMinimum()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Atencao: o saldo informado esta abaixo do valor minimo definido.')
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Fundo atualizado com sucesso.';
    }
}
