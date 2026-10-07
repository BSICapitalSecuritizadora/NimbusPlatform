<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Exceptions\MeasurementWorkflowException;
use App\Exceptions\OperationLifecycleException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Filament\Support\SurfacesUnplacedValidationErrors;
use App\Models\Measurement;
use App\Models\Operation;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateMeasurement extends CreateRecord
{
    use DetectsConcurrentUpdates;
    use SurfacesUnplacedValidationErrors;

    private const REFUSAL_TITLE = 'Medição não enviada.';

    /**
     * A regra de envio é a da participação direta na operação; a mensagem
     * padrão da autorização é em inglês e não diz isso.
     */
    private const NOT_A_DIRECT_PARTICIPANT_MESSAGE = 'Você não pode enviar medição nesta operação: o envio é feito por quem participa diretamente dela.';

    protected static string $resource = MeasurementResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected static ?string $title = 'Enviar Medição';

    protected static ?string $breadcrumb = 'Enviar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-measurement-form-page',
    ];

    public static string|Alignment $formActionsAlignment = Alignment::End;

    public function getSubheading(): ?string
    {
        return 'Envie os arquivos da competência para cada empreendimento vinculado à operação.';
    }

    /**
     * Envia a medição e mostra toda recusa do domínio.
     *
     * Os ganchos dos arquivos recusam com a chave `asset` -- antivírus fora do
     * ar ou que bloqueou o arquivo, tipo real ou tamanho inválidos --, que não
     * é campo do formulário: o envio falhava sem mensagem nenhuma. Com o
     * `CreateRecord` já tendo desfeito a transação, o erro que um campo
     * desenha continua nele, o resto vira notificação
     * ({@see SurfacesUnplacedValidationErrors}), e a recusa do fluxo também.
     * Os erros da validação do próprio formulário já são caminhos de campo e
     * seguem intactos. Fora do ciclo de uma ação não há `Halt`: aqui ele viraria
     * erro de servidor.
     *
     * A recusa dos arquivos acontece depois de a medição ser inserida, e o
     * `CreateRecord` deixa em `$record` a medição que a transação desfez. Na
     * requisição seguinte o Livewire a buscava pela chave e a página respondia
     * 404: quem lia a recusa não conseguia enviar de novo sem recarregar. Sem
     * criação não há registro, qualquer que seja a falha.
     */
    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (Throwable $exception) {
            $this->record = null;

            $this->explainRefusal($exception);
        }
    }

    /**
     * Toda falha esperada do envio vira algo que a pessoa lê:
     *
     * - `ValidationException`: no campo, quando há campo; senão, notificação;
     * - `AuthorizationException`: o seletor de Operação mostra o que a pessoa
     *   enxerga -- inclusive por delegação --, mas o envio é de quem participa
     *   diretamente da operação. A recusa saía como o aviso genérico de erro
     *   ao carregar a página, e cada nova tentativa repetia o aviso;
     * - `MeasurementWorkflowException`: o texto já é de operador;
     * - deadlock ou espera por lock estourada: a operação estava ocupada por
     *   outra gravação (um pagamento sendo registrado, a operação sendo
     *   encerrada). Nada ficou gravado; vai para o log e a pessoa tenta de
     *   novo. A mensagem fala da operação porque a medição ainda não existe.
     *
     * O resto é falha da aplicação e segue para o tratamento padrão.
     *
     * @throws Throwable o erro que tem campo, ou o que não é recusa esperada
     */
    private function explainRefusal(Throwable $exception): void
    {
        if ($exception instanceof ValidationException) {
            $fieldErrors = $this->placeValidationErrors($exception, $this->form, self::REFUSAL_TITLE);

            if ($fieldErrors !== null) {
                throw $fieldErrors;
            }

            return;
        }

        if ($exception instanceof AuthorizationException) {
            $this->refuse(self::NOT_A_DIRECT_PARTICIPANT_MESSAGE);

            return;
        }

        if ($exception instanceof MeasurementWorkflowException) {
            $this->refuse($exception->getMessage());

            return;
        }

        if (! static::isConcurrentUpdate($exception)) {
            throw $exception;
        }

        report($exception);

        $this->refuse(self::CONCURRENT_OPERATION_UPDATE_MESSAGE);
    }

    private function refuse(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(self::REFUSAL_TITLE)
            ->body($message)
            ->persistent()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor !== null, 403);

        $operation = Operation::query()
            ->visibleTo($actor)
            ->findOrFail($data['operation_id'] ?? null);
        Gate::authorize('createForOperation', [Measurement::class, $operation]);

        $data['uploaded_by'] = auth()->id();
        $data['uploaded_at'] = now();
        $data['status'] = 'pending';
        $data['current_stage'] = 1;

        return $data;
    }

    /**
     * A medição nasce sob o lock da própria operação.
     *
     * A autorização acima é feita fora de qualquer transação e, sozinha, deixaria
     * uma janela: entre ela e a inserção, outra requisição poderia concluir ou
     * cancelar a operação, e nasceria exatamente o estado que o lifecycle existe
     * para tornar inalcançável -- operação encerrada com medição aberta. Com o
     * lock, as duas transações competem pela mesma linha e apenas uma vence: ou a
     * medição existe antes e o encerramento é recusado por haver medição aberta,
     * ou o encerramento acontece antes e a criação é recusada aqui.
     *
     * A ordem é a de sempre no módulo: Operation primeiro.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor !== null, 403);

        return DB::transaction(function () use ($data, $actor): Model {
            try {
                app(OperationLifecycleService::class)->lockForNewMeasurement(
                    (int) ($data['operation_id'] ?? 0),
                    $actor,
                );
            } catch (OperationLifecycleException $exception) {
                // A recusa é do domínio, mas quem a lê está num formulário: ela
                // volta apontando para o campo que a causou.
                throw ValidationException::withMessages([
                    'data.operation_id' => $exception->getMessage(),
                ]);
            }

            return parent::handleRecordCreation($data);
        }, 3);
    }

    protected function afterCreate(): void
    {
        app(MeasurementWorkflow::class)->startReview($this->record->refresh(), auth()->user());
    }

    /**
     * Ordem visual do rodapé: terciária à esquerda, primária à direita.
     *
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCreateFormAction(),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Enviar Medição')
            ->icon('heroicon-m-arrow-up-tray');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outra')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray')
            ->extraAttributes(['class' => 'bsi-form-cancel-action']);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Medição enviada e encaminhada para análise.';
    }
}
