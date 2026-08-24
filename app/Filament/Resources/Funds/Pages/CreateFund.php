<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Actions\FundAlerts\SendFundMinimumBalanceAlertAction;
use App\Filament\Resources\Funds\FundResource;
use App\Models\Fund;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateFund extends CreateRecord
{
    protected static string $resource = FundResource::class;

    protected static ?string $title = 'Criar fundo';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-fund-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre a classificação, os dados bancários e os limites financeiros do fundo.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['balance_updated_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! ($this->record instanceof Fund)) {
            return;
        }

        app(SendFundMinimumBalanceAlertAction::class)->handle($this->record);

        if (! $this->record->isBalanceBelowMinimum()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Atenção: o saldo informado está abaixo do valor mínimo definido.')
            ->send();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar fundo')
            ->icon('heroicon-m-plus');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outro')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Fundo criado com sucesso.';
    }
}
