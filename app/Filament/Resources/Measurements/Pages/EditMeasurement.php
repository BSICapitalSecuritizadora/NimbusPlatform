<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Filament\Support\SurfacesUnplacedValidationErrors;
use App\Models\Measurement;
use App\Models\Operation;
use App\Services\MeasurementWorkflow;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditMeasurement extends EditRecord
{
    use DetectsConcurrentUpdates;
    use SurfacesUnplacedValidationErrors;

    private const REFUSAL_TITLE = 'Medição não atualizada.';

    /**
     * A aprovação terminou enquanto a gravação esperava o lock da Operation.
     */
    private const APPROVED_WHILE_EDITING_MESSAGE = 'A Engenharia aprovou esta medição enquanto você editava. Atualize a página.';

    /**
     * A aprovação já valia quando a página recebeu a requisição.
     */
    private const ALREADY_APPROVED_MESSAGE = 'A Engenharia já aprovou esta medição; ela não pode mais ser editada.';

    private const NO_LONGER_EDITABLE_MESSAGE = 'Esta medição não pode mais ser editada por você. Atualize a página para ver a situação atual.';

    private const UNAVAILABLE_MESSAGE = 'A medição não está mais disponível para edição. Atualize a página.';

    protected static string $resource = MeasurementResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected static ?string $title = 'Editar Medição';

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize os dados e arquivos da medição da operação.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-construction-form-page bsi-measurement-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /**
     * A Engenharia aprovou a medição depois que a página abriu, e a pessoa já
     * foi avisada e mandada para a visualização: nesta requisição nada mais é
     * gravado. Não é público de propósito -- não atravessa requisições e se
     * recalcula na próxima.
     */
    private bool $wasApprovedMeanwhile = false;

    /**
     * A competência e a medição prevista de cada arquivo como estavam antes
     * desta gravação, lidas sob o lock ({@see self::beforeValidate()}): a
     * conferência do período ({@see self::afterSave()}) só roda quando algo
     * disso mudou. Não atravessa requisições.
     *
     * @var array{competence: string|null, lines: array<int, int|null>}|null
     */
    private ?array $periodBeforeSave = null;

    /**
     * Quem não vê a medição continua recebendo 403. Quem a vê, mas a encontra
     * aprovada pela Engenharia -- pelo link de edição ou porque a aprovação
     * chegou com a página aberta --, lê o motivo e vai para a visualização.
     *
     * O `EditRecord` confere o acesso no mount, em toda requisição do Livewire
     * (hydrate) e no começo de `save()`. Com a policy negando a edição da
     * medição aprovada, clicar em Salvar -- ou só digitar num campo reativo --
     * devolvia o aviso genérico de erro ao carregar a página, sem dizer o que
     * mudou, e tentar de novo repetia o aviso. A requisição em que isso é
     * percebido não grava nada: {@see self::save()} e
     * {@see self::saveFormComponentOnly()} param antes do Filament.
     */
    protected function authorizeAccess(): void
    {
        if ($this->wasApprovedMeanwhile) {
            return;
        }

        $record = $this->getRecord();
        $resource = static::getResource();

        if ($resource::canEdit($record)) {
            return;
        }

        abort_unless(
            $resource::canView($record) && ($record instanceof Measurement) && $record->hasApprovedEngineering(),
            403,
        );

        $this->wasApprovedMeanwhile = true;

        $this->refuse(self::ALREADY_APPROVED_MESSAGE);

        $this->redirect($resource::getUrl('view', ['record' => $record]));
    }

    /**
     * Salva a edição e mostra toda recusa do domínio.
     *
     * Trocar o arquivo de um empreendimento passa pelos mesmos ganchos do
     * envio, que recusam com a chave `asset` (antivírus, tipo real, tamanho) --
     * sem campo no formulário, a recusa sumia. Com a transação já desfeita pelo
     * `EditRecord`, o erro que um campo desenha continua nele e o resto vira
     * notificação ({@see SurfacesUnplacedValidationErrors}); os erros da
     * validação do próprio formulário seguem intactos.
     *
     * A aprovação da Engenharia que chega durante a gravação é recusada pelo
     * fluxo, sob o lock da Operation ({@see self::beforeValidate()}); a que já
     * tinha chegado antes do clique é tratada no acesso
     * ({@see self::authorizeAccess()}). Deadlock e espera por lock estourada
     * não gravaram nada e só pedem uma nova tentativa: vão para o log e a
     * pessoa lê o aviso, sem o texto técnico.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->authorizeAccess();

        if ($this->wasApprovedMeanwhile) {
            return;
        }

        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            $fieldErrors = $this->placeValidationErrors($exception, $this->form, self::REFUSAL_TITLE);

            if ($fieldErrors !== null) {
                throw $fieldErrors;
            }
        } catch (MeasurementWorkflowException $exception) {
            $this->refuse($exception->getMessage());
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'line_claim')) {
                throw $exception;
            }

            $this->refuse('A medição prevista escolhida acabou de ser ocupada por outra medição. Recarregue a página e escolha outra.');
        } catch (Throwable $exception) {
            if (! static::isConcurrentUpdate($exception)) {
                throw $exception;
            }

            report($exception);

            $this->refuse(self::CONCURRENT_MEASUREMENT_UPDATE_MESSAGE);
        }
    }

    /**
     * O outro caminho de gravação do `EditRecord` abre a mesma transação e
     * passa pelo mesmo {@see self::beforeValidate()}; aqui só respeita a
     * recusa já dada nesta requisição.
     */
    public function saveFormComponentOnly(Component $component): void
    {
        $this->authorizeAccess();

        if ($this->wasApprovedMeanwhile) {
            return;
        }

        parent::saveFormComponentOnly($component);
    }

    /**
     * Trava a Operation e depois a medição como primeiras instruções da
     * transação da página, recarrega a medição e só então confere se ela
     * ainda pode ser editada.
     *
     * O `EditRecord` grava os arquivos (o Repeater) antes da medição, dentro
     * do `getState()`. Sem lock no começo, a página tirava a fotografia do
     * REPEATABLE READ na primeira leitura da validação, travava o arquivo no
     * UPDATE e pedia a medição por último -- o contrário da aprovação da
     * Engenharia, que trava a Operation, a medição e depois os arquivos. A
     * aprovação inteira cabia entre a leitura e a gravação da edição, e o
     * arquivo aprovado era trocado depois de aprovado (a guarda do arquivo lia
     * a fotografia antiga); com a observação também alterada, as duas se
     * travavam em ciclo e o MySQL matava uma delas (1213).
     *
     * Com a Operation à frente, edição e aprovação se serializam: a fotografia
     * da edição nasce depois do lock e já inclui a aprovação que terminou
     * enquanto ela esperava, e a recusa sai com o motivo. O `operation_id` vem
     * do registro carregado; é imutável (gancho do model). A ordem é a canônica
     * do módulo ({@see MeasurementWorkflow}).
     *
     * @throws MeasurementWorkflowException
     */
    protected function beforeValidate(): void
    {
        $record = $this->getRecord();
        $operation = Operation::query()
            ->whereKey($record->getAttribute('operation_id'))
            ->lockForUpdate()
            ->first();
        $locked = $operation instanceof Operation
            ? Measurement::query()->whereKey($record->getKey())->lockForUpdate()->first()
            : null;

        if (! $locked instanceof Measurement || ((int) $locked->operation_id !== (int) $operation->getKey())) {
            throw new MeasurementWorkflowException(self::UNAVAILABLE_MESSAGE, [
                'measurement_id' => $record->getKey(),
            ]);
        }

        $record->setRawAttributes($locked->getAttributes(), sync: true);
        $record->setRelations(['operation' => $operation]);

        if ($locked->hasApprovedEngineering()) {
            throw new MeasurementWorkflowException(self::APPROVED_WHILE_EDITING_MESSAGE, [
                'measurement_id' => $locked->getKey(),
                'operation_id' => $locked->operation_id,
            ]);
        }

        if (! static::getResource()::canEdit($record)) {
            throw new MeasurementWorkflowException(self::NO_LONGER_EDITABLE_MESSAGE, [
                'measurement_id' => $locked->getKey(),
                'operation_id' => $locked->operation_id,
            ]);
        }

        $this->periodBeforeSave = $this->period($locked);
    }

    /**
     * Ainda dentro da transação da página: se a competência, a medição
     * prevista de algum arquivo ou os arquivos mudaram, a medição precisa
     * continuar com uma competência só -- a de todas as linhas, regida pelas
     * versões congeladas -- e cobrindo os empreendimentos que a Engenharia vai
     * exigir nela. O `EditRecord` grava os arquivos antes da medição, e a
     * competência pode voltar ao valor gravado depois de a linha mudar: a
     * conferência olha o resultado, não o que ficou sujo. A recusa desfaz a
     * gravação inteira e vira notificação ({@see self::save()}).
     *
     * @throws MeasurementWorkflowException
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Measurement || $this->periodBeforeSave === $this->period($record)) {
            return;
        }

        $record->assertFilesFollowItsCompetence();
        $record->assertCoversItsCompetence();
    }

    /**
     * @return array{competence: string|null, lines: array<int, int|null>}
     */
    private function period(Measurement $measurement): array
    {
        $competence = Measurement::query()->whereKey($measurement->getKey())->value('reference_month');

        return [
            'competence' => $competence === null ? null : substr((string) $competence, 0, 7),
            'lines' => $measurement->assets()
                ->orderBy('id')
                ->pluck('plan_line_id', 'id')
                ->map(fn (mixed $lineId): ?int => $lineId === null ? null : (int) $lineId)
                ->all(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Visualizar')
                ->icon('heroicon-m-eye')
                ->color('gray'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Medição atualizada com sucesso.';
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
}
