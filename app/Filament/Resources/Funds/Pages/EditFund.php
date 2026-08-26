<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Actions\FundAlerts\SendFundMinimumBalanceAlertAction;
use App\Filament\Resources\Funds\FundResource;
use App\Models\Fund;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFund extends EditRecord
{
    protected static string $resource = FundResource::class;

    protected static ?string $title = 'Editar fundo';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a classificação, os dados bancários e os limites financeiros do fundo.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-construction-form-page bsi-fund-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->getRecord()->requiresMonthlyBalanceUpdate()) {
            Notification::make()
                ->warning()
                ->title('Atualização mensal de saldo pendente.')
                ->body('Confirme e salve o saldo deste fundo. O valor do mês anterior será preservado automaticamente no histórico.')
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir fundo'),
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

        app(SendFundMinimumBalanceAlertAction::class)->handle($this->getRecord());

        if (! $this->getRecord()->isBalanceBelowMinimum()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Atenção: o saldo informado está abaixo do valor mínimo definido.')
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Fundo atualizado com sucesso.';
    }
}
