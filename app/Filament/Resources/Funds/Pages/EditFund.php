<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Actions\FundAlerts\SendFundMinimumBalanceAlertAction;
use App\Actions\Funds\DeriveLegacyBankFieldsFromAccount;
use App\Exceptions\Funds\FundAllocationException;
use App\Filament\Resources\Funds\FundResource;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Models\Fund;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditFund extends EditRecord
{
    use DetectsConcurrentUpdates;

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
     * A troca de operação, conta ou aplicação vinculada é recusada pelo
     * domínio quando o fundo participa de uma alocação em andamento; a
     * mensagem já é texto final em português. Deadlock e espera por lock
     * estourada não gravaram nada e só pedem uma nova tentativa.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (FundAllocationException $exception) {
            $this->refuse($exception->getMessage());
        } catch (Throwable $exception) {
            if (! static::isConcurrentUpdate($exception)) {
                throw $exception;
            }

            report($exception);

            $this->refuse('Outra pessoa está atualizando este fundo ou aprovando uma alocação dele. Tente novamente em instantes.');
        }
    }

    private function refuse(string $message): void
    {
        Notification::make()
            ->danger()
            ->title('Fundo não atualizado.')
            ->body($message)
            ->persistent()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->getRecord()->snapshotPreviousMonthBalanceIfMissing();
        $data = app(DeriveLegacyBankFieldsFromAccount::class)->handle($data);
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
