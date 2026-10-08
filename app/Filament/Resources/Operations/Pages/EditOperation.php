<?php

namespace App\Filament\Resources\Operations\Pages;

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Operations\OperationResource;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Models\Operation;
use App\Models\User;
use App\Services\OperationContextMutationService;
use App\Services\OperationContextVisibilityService;
use App\Services\OperationResponsibilityService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditOperation extends EditRecord
{
    use DetectsConcurrentUpdates;

    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Editar Operação de Obra';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize a operação, os empreendimentos e os responsáveis pelo fluxo de medição.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-operation-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $developments = [];

    /**
     * Os dados da operação já foram gravados nesta tentativa de salvar? A
     * página não tem transação própria: a operação é gravada pelo serviço de
     * contexto e os empreendimentos depois, cada um por si.
     */
    private bool $operationWasUpdated = false;

    /**
     * Salva a operação e mostra a recusa do domínio da Medição.
     *
     * O fundo de obra de um empreendimento coberto por Engenharia aprovada não
     * muda, e a troca de emissão de operação aprovada também não: os ganchos
     * recusam com `MeasurementWorkflowException`, que não é reportada e virava
     * o aviso genérico de erro, sem dizer o que não foi salvo. Quando a recusa
     * vem dos empreendimentos, a operação já foi gravada antes deles -- a
     * notificação diz isso, em vez de "nada foi salvo".
     *
     * Os empreendimentos são gravados pelo serviço de planos, que trava a
     * Operation: a espera pelo lock pode estourar enquanto um pagamento ou uma
     * Finalização a seguram, e o aviso pede nova tentativa em vez de erro.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->operationWasUpdated = false;

        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (MeasurementWorkflowException $refusal) {
            $this->notifyDevelopmentsRefusal($refusal->getMessage());
        } catch (Halt|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! static::isConcurrentUpdate($exception)) {
                throw $exception;
            }

            report($exception);
            $this->notifyDevelopmentsRefusal(self::CONCURRENT_OPERATION_UPDATE_MESSAGE);
        }
    }

    private function notifyDevelopmentsRefusal(string $message): void
    {
        Notification::make()
            ->danger()
            ->title($this->operationWasUpdated ? 'Empreendimentos não atualizados.' : 'Operação não atualizada.')
            ->body($this->operationWasUpdated
                ? $message.' Os demais dados da operação foram salvos.'
                : $message)
            ->persistent()
            ->send();
    }

    /**
     * Exclusão física saiu daqui: o ciclo de vida é Ativar / Concluir / Cancelar
     * / Reabrir, e cada uma dessas ações preserva o histórico em vez de apagá-lo.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar')
                ->icon('heroicon-m-eye')
                ->color('gray'),
            OperationResource::getActivateOperationAction()->record($this->record),
            OperationResource::getCompleteOperationAction()->record($this->record),
            OperationResource::getCancelOperationAction()->record($this->record),
            OperationResource::getReopenOperationAction()->record($this->record),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        app(OperationResponsibilityService::class)->assertCanChange($actor, $this->record, $data);

        $this->developments = $data['developments'] ?? [];
        app(OperationContextVisibilityService::class)->assertOperationPayloadIsVisible(
            $actor,
            $data['emission_id'] ?? $this->record->emission_id,
            $this->developments,
        );
        unset($data['developments']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Operation) {
            return parent::handleRecordUpdate($record, $data);
        }

        $updated = app(OperationContextMutationService::class)->update($record, $data);
        $this->operationWasUpdated = true;

        return $updated;
    }

    protected function afterSave(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $this->record->syncDevelopmentPlans($this->developments, $actor);
        } catch (ValidationException $exception) {
            // As mensagens do serviço de planos não trazem o caminho do campo
            // no repeater: viram a recusa dos empreendimentos, com o motivo.
            throw new MeasurementWorkflowException(collect($exception->errors())->flatten()->unique()->implode(' '));
        }

        // A página continua aberta depois de salvar: o fundo mostrado e o
        // contador do rascunho voltam do banco, senão a próxima gravação
        // compararia com o que valia antes desta e acusaria outra pessoa.
        $this->refreshFormData(['developments']);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Operação de obra atualizada com sucesso.';
    }
}
