<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Concerns\MoneyFormatter;
use App\DTOs\Measurements\MeasurementAmountDue;
use App\DTOs\Measurements\MeasurementAmountDueLine;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementReconciliationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Support\DetectsConcurrentUpdates;
use App\Filament\Support\SurfacesUnplacedValidationErrors;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementFinancialRuleService;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ViewMeasurement extends ViewRecord
{
    use DetectsConcurrentUpdates;
    use SurfacesUnplacedValidationErrors;

    /**
     * Título de toda recusa que a pessoa lê nesta página. Os textos abaixo
     * respondem ao que o domínio não explica: a ação que deixou de valer entre
     * abrir e enviar o modal, a autorização perdida (cuja mensagem padrão é em
     * inglês) e as falhas da aplicação, cujo texto técnico nunca chega à pessoa.
     */
    private const REFUSAL_TITLE = 'Ação não concluída.';

    private const UNAVAILABLE_ACTION_MESSAGE = 'A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.';

    private const LOST_AUTHORIZATION_MESSAGE = 'Você não tem permissão para concluir esta ação nesta medição. Atualize a página para ver a situação atual.';

    private const CONCURRENT_UPDATE_MESSAGE = 'A medição está sendo atualizada por outra pessoa. Tente novamente em instantes.';

    private const UNEXPECTED_FAILURE_MESSAGE = 'Não foi possível concluir a ação agora. Atualize a página para conferir a situação da medição antes de tentar de novo.';

    /**
     * O que falta para aprovar a etapa Pagamento quando não há pagamento
     * válido: a justificativa da ausência não substitui o pagamento.
     */
    private const PAYMENT_REQUIRED_MESSAGE = 'Cadastre ao menos um pagamento antes de aprovar a etapa Pagamento.';

    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Medição';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-measurement-view-page',
    ];

    public function getSubheading(): string|Htmlable|null
    {
        $id = $this->record->id;
        $op = $this->record->operation;
        $opLabel = $op ? "{$op->code} · {$op->title}" : null;
        $refMonth = $this->record->reference_month ? $this->record->reference_month->format('m/Y') : '—';
        $statusLabel = $this->record->workflowStatusLabel();
        $revisionLabel = $this->record->isRevision()
            ? 'Revisão '.$this->record->revisionLabel().' · '.$this->record->revisionStatus()->label()
            : ($this->record->isEffectiveRevision() ? null : 'Original · '.$this->record->revisionStatus()->label());
        $stageId = $this->stage();
        $stageLabel = MeasurementWorkflow::STAGE_LABELS[$stageId] ?? '—';
        $uploadedAt = $this->record->uploaded_at ? $this->record->uploaded_at->format('d/m/Y H:i') : null;
        $uploader = $this->record->uploadedByUser?->name;

        return new HtmlString(
            '<div class="bsi-measurement-header-meta">'
            .'<div class="bsi-measurement-meta-row bsi-measurement-meta-row--primary">'
            .'<span class="bsi-measurement-meta-id">Medição #'.e($id).'</span>'
            .($opLabel ? '<span class="bsi-measurement-meta-op" title="'.e($opLabel).'">'.e($opLabel).'</span>' : '')
            .'<span class="bsi-measurement-badge bsi-measurement-badge--status">'.e($statusLabel).'</span>'
            .($revisionLabel ? '<span class="bsi-measurement-badge bsi-measurement-badge--stage">'.e($revisionLabel).'</span>' : '')
            .'<span class="bsi-measurement-badge bsi-measurement-badge--stage">Etapa: '.e($stageLabel).'</span>'
            .'</div>'
            .'<div class="bsi-measurement-meta-row bsi-measurement-meta-row--secondary">'
            .'<span><strong class="bsi-meta-label">Competência:</strong> '.e($refMonth).'</span>'
            .($uploadedAt ? '<span class="bsi-meta-sep" aria-hidden="true">•</span><span><strong class="bsi-meta-label">Enviada em:</strong> '.e($uploadedAt).'</span>' : '')
            .($uploader ? '<span class="bsi-meta-sep" aria-hidden="true">•</span><span><strong class="bsi-meta-label">Por:</strong> '.e($uploader).'</span>' : '')
            .'</div>'
            .'</div>'
        );
    }

    private const ENGINEERING_STAGE = 1;

    /**
     * A Gestão: recusar nela devolve a medição à Engenharia.
     */
    private const MANAGEMENT_STAGE = 2;

    /**
     * As situações da revisão vigente em que "Criar revisão" aparece: a
     * finalizada (caso A), a parada na etapa Pagamento (caso B) e a paga na
     * Finalização (caso C, que exige a documentação financeira completa). Fora
     * do estado exato que o domínio aceita, o botão fica desabilitado com o
     * motivo dele ({@see MeasurementRevisionService::creationBlockReason()}).
     *
     * @var list<string>
     */
    private const REVISABLE_STATUSES = ['finalized', 'awaiting_payment', 'awaiting_receipt', 'approved'];

    /**
     * As contribuições físicas em reanálise da Engenharia na operação desta
     * medição, por empreendimento, lidas uma vez por requisição: os modais da
     * Engenharia mostram cada uma fora do avanço reconhecido. Propriedade
     * privada: o Livewire não a guarda entre requisições.
     *
     * @var array<int, list<array{measurement_id: int, revision_label: string, reference_month: string|null, basis_points: int, plan_line_lineage_key: string|null, invalidated_at: CarbonImmutable|null}>>|null
     */
    private ?array $reanalysisContributionsByPlanSet = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->concludeWithoutDisbursementAction(),
            $this->rejectAction(),
            $this->pauseAction(),
            $this->resumeAction(),
            $this->registerPaymentAction(),
            $this->reassessPaymentAction(),
            $this->attachReceiptAction(),
            $this->attachReceiptAction(postFinalization: true),
            $this->reviewReceiptAction(),
            $this->returnToStageAction(),
            $this->finalizeAction(),
            $this->editRevisionAction(),
            $this->submitRevisionAction(),
            $this->cancelRevisionAction(),
            $this->createRevisionAction(),
        ];
    }

    private function workflow(): MeasurementWorkflow
    {
        return app(MeasurementWorkflow::class);
    }

    private function revisions(): MeasurementRevisionService
    {
        return app(MeasurementRevisionService::class);
    }

    /**
     * Criar a revisão desta medição (a R1 da original, a R2 da R1...): à vista
     * de quem revisa a vigente finalizada, parada na etapa Pagamento ou paga na
     * Finalização ({@see self::REVISABLE_STATUSES}), e desabilitada com o
     * motivo quando o serviço recusaria -- pagamento com a etapa Pagamento
     * aberta, comprovante não aprovado, revisão já em andamento, registro da
     * Engenharia anterior ao snapshot. O serviço confere tudo de novo, sob o
     * lock.
     */
    private function createRevisionAction(): Action
    {
        return Action::make('createRevision')
            ->label('Criar revisão')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->modalHeading('Criar revisão desta medição')
            ->modalDescription(fn (): string => $this->createRevisionDescription())
            ->visible(fn (): bool => $this->record->isEffectiveRevision()
                && in_array($this->record->status, self::REVISABLE_STATUSES, true)
                && $this->actor()->can('revise', $this->record))
            ->disabled(fn (): bool => $this->revisions()->creationBlockReason($this->record) !== null)
            ->tooltip(fn (): ?string => $this->revisions()->creationBlockReason($this->record))
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Textarea::make('reason')
                    ->label('Motivo da revisão')
                    ->helperText('O que está errado nesta medição e o que a revisão corrige. O motivo fica registrado na trilha de auditoria e não pode ser alterado.')
                    ->required()
                    ->maxLength(MeasurementRevisionService::REASON_MAX_LENGTH)
                    ->rows(4),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $revision = $this->revisions()->create(
                        $this->record,
                        $this->actor(),
                        (string) $data['reason'],
                        (int) $data['expected_revision'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Revisão '.$revision->revisionLabel().' criada como rascunho.')
                        ->body('Confira os arquivos e envie a revisão para a Engenharia.')
                        ->send();

                    $this->redirect(MeasurementResource::getUrl('view', ['record' => $revision]));
                });
            });
    }

    /**
     * O que acontece com esta medição ao revisá-la. Com pagamento registrado,
     * a pessoa lê quantos e quanto, e que eles ficam nesta medição como
     * histórico, nunca copiados para a revisão. Com o fluxo ainda aberto
     * (etapa Pagamento, ou a Finalização da medição paga), lê também que o
     * envio da revisão suspende esse fluxo; a finalizada não tem fluxo a
     * suspender.
     */
    private function createRevisionDescription(): string
    {
        $text = 'A revisão nasce como rascunho, com os arquivos, a versão do plano e a medição prevista desta medição. Esta medição continua valendo, sem nenhuma alteração, até a revisão ser aprovada pela Engenharia, pela Gestão e pela Compliance. Os pagamentos já registrados nunca são alterados.';
        $payments = $this->record->payments()->get(['id', 'amount']);

        if ($payments->isNotEmpty()) {
            $text .= sprintf(
                ' Esta medição tem pagamento registrado (%d %s, %s). A revisão preserva esse histórico: os pagamentos continuam vinculados a esta medição e nunca são copiados para a revisão.',
                $payments->count(),
                $payments->count() === 1 ? 'pagamento' : 'pagamentos',
                MeasurementFinancialReconciliationService::formatCurrency($payments->reduce(
                    fn (string $total, MeasurementPayment $payment): string => bcadd($total, (string) $payment->amount, 2),
                    '0.00',
                )),
            );
        }

        if ($this->record->isOpen()) {
            $text .= ' Depois do envio da revisão, o fluxo desta medição fica suspenso (nenhum pagamento, comprovante, Finalização ou devolução) até a revisão ser recusada ou passar a valer.';
        }

        return $text;
    }

    private function editRevisionAction(): Action
    {
        return Action::make('editRevision')
            ->label('Editar revisão')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => $this->record->isRevision() && $this->actor()->can('update', $this->record))
            ->url(fn (): string => MeasurementResource::getUrl('edit', ['record' => $this->record]));
    }

    private function submitRevisionAction(): Action
    {
        return Action::make('submitRevision')
            ->label('Enviar revisão')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Enviar a revisão '.$this->record->revisionLabel().' para a Engenharia')
            ->modalDescription(fn (): string => $this->submitRevisionDescription())
            ->visible(fn (): bool => $this->record->isDraftRevision() && $this->actor()->can('revise', $this->record))
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->revisions()->submit($this->record, $this->actor(), (int) $data['expected_revision']);
                    $this->notify('Revisão enviada para a Engenharia.');
                });
            });
    }

    /**
     * O que o envio faz com a revisão anterior. Com o fluxo dela ainda aberto
     * -- parada na etapa Pagamento, ou paga na Finalização --, ele fica
     * suspenso enquanto a revisão estiver em análise, e os pagamentos dela
     * ficam nela como histórico. A finalizada não tem fluxo a suspender.
     */
    private function submitRevisionDescription(): string
    {
        $previous = $this->record->previousRevision()->first(['id', 'revision_number', 'revision_status', 'status', 'current_stage']);
        $label = $previous instanceof Measurement ? $previous->revisionLabel() : 'revisão anterior';
        $text = "A revisão segue o fluxo completo: Engenharia, Gestão e Compliance. A {$label} continua valendo até a Compliance aprovar a revisão.";

        if (! $previous instanceof Measurement || ! $previous->isOpen()) {
            return $text;
        }

        $stageLabel = MeasurementWorkflow::STAGE_LABELS[$this->workflow()->unifiedStage($previous)] ?? null;
        $text .= " Enquanto a revisão estiver em análise, o fluxo da {$label}"
            .($stageLabel === null ? '' : ", parada na etapa {$stageLabel},")
            .' fica suspenso: nenhum pagamento, comprovante, Finalização ou devolução.';

        if ($previous->payments()->exists()) {
            $text .= " Os pagamentos registrados na {$label} continuam vinculados a ela, como histórico, e nunca são copiados para a revisão.";
        }

        return $text;
    }

    private function cancelRevisionAction(): Action
    {
        return Action::make('cancelRevision')
            ->label('Cancelar revisão')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalHeading(fn (): string => 'Cancelar a revisão '.$this->record->revisionLabel())
            ->modalDescription('O rascunho fica como histórico, com o motivo do cancelamento, e o número da revisão não volta a ser usado. A medição vigente não muda.')
            ->visible(fn (): bool => $this->record->isDraftRevision()
                && app(MeasurementAuthorizationService::class)->canManageRevision($this->actor(), $this->record))
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Textarea::make('reason')
                    ->label('Motivo do cancelamento')
                    ->required()
                    ->maxLength(MeasurementRevisionService::REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->revisions()->cancel($this->record, $this->actor(), (string) $data['reason'], (int) $data['expected_revision']);
                    $this->notify('Revisão cancelada.');
                });
            });
    }

    private function stage(): int
    {
        return $this->workflow()->unifiedStage($this->record);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function notify(string $message): void
    {
        Notification::make()->success()->title($message)->send();

        $this->record->refresh();
    }

    /**
     * Envio de um modal cuja ação deixou de valer para quem o abriu.
     *
     * Entre abrir o modal e enviar, outra pessoa pode decidir, pausar, pagar,
     * finalizar ou conferir: a ação fica oculta (ou desabilitada) para o
     * registro recarregado, e o Filament descartava o envio em silêncio -- o
     * modal ficava aberto e o botão não fazia nada. A pessoa agora lê o motivo
     * e o modal fecha. É também o que responde a um envio forjado de ação
     * desabilitada, como a recusa que encerraria uma medição paga.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function callMountedAction(array $arguments = []): mixed
    {
        $action = $this->getMountedAction();

        if (($action instanceof Action) && $action->isDisabled()) {
            Notification::make()
                ->danger()
                ->title(self::REFUSAL_TITLE)
                ->body(self::UNAVAILABLE_ACTION_MESSAGE)
                ->persistent()
                ->send();

            $this->unmountAction();

            return null;
        }

        return parent::callMountedAction($arguments);
    }

    /**
     * Executa a ação do fluxo e devolve toda recusa como algo que a pessoa lê.
     *
     * - `ValidationException`: o erro que um campo visível do modal desenha
     *   fica nele; o resto -- chave sem campo, como `payments` na etapa
     *   Pagamento ou o arquivo da Engenharia que sumiu do armazenamento -- vira
     *   uma notificação ({@see SurfacesUnplacedValidationErrors}).
     * - `MeasurementWorkflowException`: o texto já é de operador -- "A etapa
     *   desta medição foi alterada por outra ação. Atualize a página." é a
     *   proteção contra submissão obsoleta falando. Recarrega o registro, para
     *   a tela refletir o estado que passou a valer.
     * - `AuthorizationException`: algumas saem do domínio sem mensagem, e o
     *   texto padrão seria em inglês; a pessoa lê sempre o mesmo aviso.
     * - Qualquer outra falha é da aplicação, não da regra: vai para o log e a
     *   pessoa recebe um aviso sem o texto técnico. Deadlock (1213, SQLSTATE
     *   40001) e espera por lock estourada (1205) só pedem uma nova tentativa
     *   ({@see DetectsConcurrentUpdates}, o mesmo detector das demais telas).
     *   `Halt`, `Cancel` e as respostas HTTP seguem o caminho do Filament.
     *
     * A ação para com o modal aberto e nada fica gravado: cada serviço do fluxo
     * desfaz a própria transação antes de a exceção chegar aqui.
     */
    private function guarded(Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            throw $this->placeValidationErrors($exception, $this->getMountedActionSchema(), self::REFUSAL_TITLE) ?? new Halt;
        } catch (MeasurementWorkflowException $exception) {
            $this->refuse($exception->getMessage(), refreshRecord: true);
        } catch (AuthorizationException) {
            $this->refuse(self::LOST_AUTHORIZATION_MESSAGE, refreshRecord: true);
        } catch (Halt|Cancel|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->refuse(static::isConcurrentUpdate($exception) ? self::CONCURRENT_UPDATE_MESSAGE : self::UNEXPECTED_FAILURE_MESSAGE);
        }
    }

    /**
     * Notifica a recusa e interrompe a ação, com o modal aberto.
     *
     * Só a recusa do domínio recarrega o registro: depois de uma falha
     * inesperada a própria leitura pode falhar de novo, e nada mudou -- a
     * transação já foi desfeita.
     */
    private function refuse(string $message, bool $refreshRecord = false): never
    {
        Notification::make()
            ->danger()
            ->title(self::REFUSAL_TITLE)
            ->body($message)
            ->persistent()
            ->send();

        if ($refreshRecord) {
            $this->record->refresh();
        }

        throw new Halt;
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar Etapa')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            // A original sem pagamento em que nada é devido termina a etapa
            // Pagamento pela decisão sem desembolso, não por esta aprovação.
            ->visible(fn (): bool => $this->workflow()->canApprove($this->record, $this->actor())
                && ! $this->workflow()->canConcludeWithoutDisbursement($this->record, $this->actor()))
            ->schema(fn (): array => $this->approveSchema())
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $progress = isset($data['realized']) && is_array($data['realized']) ? $data['realized'] : [];
                    $this->workflow()->approve(
                        $this->record,
                        $this->actor(),
                        $data['notes'] ?? null,
                        $progress,
                        expectedStage: (int) $data['expected_stage'],
                        expectedRevision: (int) $data['expected_revision'],
                    );
                    $this->notify('Etapa aprovada.');
                });
            });
    }

    /**
     * @return array<int, Component>
     */
    private function approveSchema(): array
    {
        $schema = [
            Hidden::make('expected_stage')->default(fn (): int => $this->stage()),
            Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
        ];

        if ($this->stage() === MeasurementWorkflow::STAGE_PAYMENT) {
            return [...$schema, ...$this->paymentStageApprovalFields()];
        }

        $schema[] = Textarea::make('notes')->label('Comentário (opcional)')->rows(3);

        if ($this->record->current_stage !== self::ENGINEERING_STAGE) {
            return $schema;
        }

        $planSets = $this->coveredPlanSets();

        if ($planSets->isEmpty()) {
            return $schema;
        }

        // Na revisão em análise, a conta mostra o empreendimento sem a revisão
        // vigente que ela vai substituir -- atual − vigente + revisão.
        $exclusionId = $this->revisions()->progressExclusionId($this->record);
        $physicalProgress = app(MeasurementPhysicalProgressService::class)
            ->forOperation((int) $this->record->operation_id, $exclusionId);
        $previousPercents = $this->previousRevisionPercents();
        $reanalysis = $this->reanalysisContributions();

        // Sem preenchimento: a linha guarda o valor de uma aprovação que pode ter
        // deixado de valer, e com 0% aceito um padrão viraria aprovação silenciosa.
        $fields = $planSets->flatMap(function ($planSet) use ($physicalProgress, $exclusionId, $previousPercents, $reanalysis): array {
            $label = $planSet->construction?->development_name ?? $planSet->name;
            $progress = $physicalProgress[(int) $planSet->id]
                ?? app(MeasurementPhysicalProgressService::class)->forPlanSet($planSet, $exclusionId);

            return [
                Placeholder::make("physical_progress_context.{$planSet->id}")
                    ->hiddenLabel()
                    ->content($this->physicalProgressContent(
                        $label,
                        $progress,
                        $this->capturedVersionLabel((int) $planSet->id),
                        $previousPercents[(int) $planSet->id] ?? null,
                        $reanalysis[(int) $planSet->id] ?? [],
                    )),
                TextInput::make("realized.{$planSet->id}")
                    ->label($label)
                    ->numeric()
                    ->step('0.01')
                    ->suffix('%')
                    ->required()
                    ->minValue(0)
                    ->maxValue(100)
                    ->rule('decimal:0,2')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($label, $progress): void {
                        $basisPoints = MeasurementPhysicalProgress::basisPoints($value);

                        if ($basisPoints !== null && $basisPoints >= 0 && $progress->exceedsLimitWith($basisPoints)) {
                            $fail($progress->limitExceededMessage($label, $basisPoints));
                        }
                    })
                    ->validationMessages([
                        'decimal' => 'Informe o percentual realizado com no máximo duas casas decimais.',
                        'min' => 'O percentual realizado não pode ser negativo. Para corrigir uma medição já aprovada, devolva-a à Engenharia; se ela tiver pagamento registrado, a correção dos dados aprovados é uma revisão formal (Criar revisão).',
                    ]),
            ];
        })->all();

        $schema[] = Section::make('Realizado mensal por empreendimento (%)')
            ->description('Informe o avanço físico realizado no mês de referência (0% quando a obra não avançou). O avanço do empreendimento — inicial mais as medições com a Engenharia vigente — não pode passar de 100%.')
            ->schema($fields);

        return $schema;
    }

    /**
     * Comentário da aprovação da etapa Pagamento -- ou a justificativa da
     * ausência de pagamento, quando ela é exigida.
     *
     * Empreendimento que a Engenharia aprovou com valor a pagar e ficou sem
     * pagamento nesta medição só passa pela etapa com o porquê na nota da
     * aprovação, que o Finalizador vai aceitar expressamente
     * ({@see MeasurementPaymentFinancialService::unpaidRequiredPlanSets()}). A
     * pessoa vê a lista antes de enviar, e o campo obrigatório responde com a
     * mesma frase do domínio. A chave continua `notes`: é nela que o domínio
     * devolve a recusa e é ela que vira a nota da aprovação.
     *
     * Sem nenhum pagamento válido a etapa não é aprovada de jeito nenhum
     * ({@see MeasurementWorkflow::hasValidPayment()}): o modal listava todos
     * os empreendimentos, mandava justificar a ausência e o domínio recusava a
     * justificativa. Aí ele só diz o que falta, sem oferecer a justificativa
     * como saída.
     *
     * Na revisão em que a família pagou mais do que ela aprova, a decisão
     * financeira é exigida só pela parte que nenhuma Finalização anterior
     * reconheceu ({@see MeasurementRevisionService::unacknowledgedOverpayments()},
     * o mesmo filtro das posições); a parte já reconhecida aparece como
     * informação, sem campo obrigatório -- e continua em aberto.
     *
     * @return array<int, Component>
     */
    private function paymentStageApprovalFields(): array
    {
        if (! $this->workflow()->hasValidPayment($this->record)) {
            // Sem pagamento, a pessoa vê também por que a etapa não pode terminar
            // sem desembolso: o valor devido de cada empreendimento, ou por que
            // ele não pode ser determinado -- nunca tomado como zero.
            $amountDue = ! $this->record->isRevision() && ! $this->record->payments()->exists()
                ? app(MeasurementPaymentFinancialService::class)->amountDue($this->record)
                : null;

            return [
                Placeholder::make('payment_required')
                    ->hiddenLabel()
                    ->content(new HtmlString('<p class="text-sm text-gray-600 dark:text-gray-300">'.e(self::PAYMENT_REQUIRED_MESSAGE).'</p>')),
                ...($amountDue === null || $amountDue->refusals() === [] ? [] : [
                    Placeholder::make('amount_due_refusals')
                        ->label('Por que a etapa não pode ser concluída sem desembolso')
                        ->content(new HtmlString('<ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'
                            .collect($amountDue->refusals())->map(fn (string $refusal): string => '<li>'.e($refusal).'</li>')->implode('')
                            .'</ul>')),
                ]),
            ];
        }

        $financial = app(MeasurementPaymentFinancialService::class);
        $unpaid = $financial->unpaidRequiredPlanSets($this->record);
        $positions = $this->revisions()->positions($this->record);
        $overpayments = array_values(array_filter(
            $positions,
            fn (MeasurementRevisionPlanSetPosition $position): bool => $position->hasUnacknowledgedOverpayment(),
        ));
        $outstanding = $this->outstandingHistoricalOverpayments($positions);

        if ($overpayments !== []) {
            return [
                ...($unpaid === [] ? [] : [Placeholder::make('unpaid_plan_sets')
                    ->label('Empreendimentos sem pagamento nesta competência')
                    ->content($this->unpaidPlanSetsContent($unpaid))]),
                Placeholder::make('revision_overpayments')
                    ->label('Valor pago a maior em aberto')
                    ->content($this->revisionOverpaymentsContent($outstanding)),
                Textarea::make('notes')
                    ->label('Decisão financeira')
                    ->helperText('Registre a decisão sobre o valor pago a maior ainda não reconhecido'.($unpaid === [] ? '' : ' e a justificativa da ausência de pagamento').'. A decisão não devolve nem regulariza o valor: os pagamentos registrados não são alterados nem estornados, e o valor continua em aberto. O Finalizador precisará aceitar a decisão expressamente.')
                    ->required()
                    ->rows(4)
                    ->validationMessages([
                        'required' => 'Registre a decisão financeira sobre o valor pago a maior ainda não reconhecido: '.$this->revisions()->describeOverpayments($overpayments).'.',
                    ]),
            ];
        }

        $acknowledgedBefore = $outstanding === [] ? [] : [
            Placeholder::make('revision_overpayments_acknowledged')
                ->label('Valor pago a maior em aberto, reconhecido anteriormente')
                ->content($this->previouslyAcknowledgedOverpaymentsContent($outstanding)),
        ];

        if ($unpaid === []) {
            return [...$acknowledgedBefore, Textarea::make('notes')->label('Comentário (opcional)')->rows(3)];
        }

        return [
            Placeholder::make('unpaid_plan_sets')
                ->label('Empreendimentos sem pagamento nesta competência')
                ->content($this->unpaidPlanSetsContent($unpaid)),
            ...$acknowledgedBefore,
            Textarea::make('notes')
                ->label('Justificativa da ausência de pagamento')
                ->helperText('Explique por que estes empreendimentos ficam sem pagamento nesta competência. O Finalizador precisará aceitar a ausência expressamente.')
                ->required()
                ->rows(3)
                ->validationMessages([
                    'required' => 'Justifique a ausência de pagamento nesta competência de: '.$financial->describeUnpaidPlanSets($unpaid).'; o Finalizador precisará aceitar expressamente.',
                ]),
        ];
    }

    /**
     * Conclui a etapa Pagamento da medição original sem desembolso: nenhum
     * pagamento registrado e nada devido, demonstrado
     * ({@see MeasurementPaymentFinancialService::amountDue()}). A pessoa vê a
     * posição de cada empreendimento -- Fundo de Obra, realizado do mês e
     * valor devido -- e registra a justificativa obrigatória; a Finalização
     * precisará confirmar ciência da decisão. Nenhum pagamento é criado, nem de
     * valor zero. A ação só aparece a quem decide a etapa, e o domínio confere
     * tudo de novo, sob o lock.
     */
    private function concludeWithoutDisbursementAction(): Action
    {
        return Action::make('concludeWithoutDisbursement')
            ->label('Concluir etapa sem desembolso')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Decisão de pagamento sem desembolso')
            ->modalDescription('Medição sem desembolso financeiro: nenhum pagamento é devido nem será registrado. A Finalização deverá confirmar ciência desta decisão.')
            ->modalSubmitActionLabel('Concluir etapa sem desembolso')
            ->visible(fn (): bool => $this->workflow()->canConcludeWithoutDisbursement($this->record, $this->actor()))
            ->schema(fn (): array => [
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Placeholder::make('amount_due')
                    ->label('Nenhum pagamento devido')
                    ->content(fn (): HtmlString => $this->amountDueContent(app(MeasurementPaymentFinancialService::class)->amountDue($this->record))),
                Textarea::make('notes')
                    ->label('Justificativa obrigatória')
                    ->helperText('Informe a justificativa para concluir a etapa sem pagamento. A Finalização deverá confirmar ciência desta decisão. A decisão não é pagamento, estorno, devolução nem regularização financeira.')
                    ->required()
                    ->rows(4)
                    ->maxLength(5000)
                    ->validationMessages([
                        'required' => MeasurementWorkflow::NO_DISBURSEMENT_JUSTIFICATION_REQUIRED,
                    ]),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->workflow()->approve(
                        $this->record,
                        $this->actor(),
                        $data['notes'] ?? null,
                        expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
                        expectedRevision: (int) $data['expected_revision'],
                        withoutDisbursement: true,
                    );
                    $this->notify('Etapa Pagamento concluída sem desembolso. A Finalização deverá confirmar ciência desta decisão.');
                });
            });
    }

    /**
     * A posição de cada empreendimento como a pessoa confere antes de decidir:
     * Fundo de Obra congelado, realizado do mês e valor devido.
     */
    private function amountDueContent(MeasurementAmountDue $amountDue): HtmlString
    {
        $items = collect($amountDue->lines)
            ->map(fn (MeasurementAmountDueLine $line): string => sprintf(
                '<li><span class="font-medium text-gray-950 dark:text-white">%s</span> · Fundo de Obra %s · realizado no mês %s · <span class="font-medium">Valor devido: %s</span></li>',
                e($line->label),
                e(MeasurementFinancialReconciliationService::formatCurrency($line->fundAmount)),
                e(MeasurementFinancialReconciliationService::formatPercent($line->realizedMonthlyPercent)),
                e(MeasurementFinancialReconciliationService::formatCurrency($line->remainingAmount)),
            ))
            ->implode('');

        return new HtmlString(
            '<p class="text-sm text-gray-600 dark:text-gray-300">Não há desembolso financeiro nesta medição: o valor aprovado pela Engenharia é zero em todos os empreendimentos e nenhum pagamento foi registrado.</p>'
            .'<ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.$items.'</ul>'
        );
    }

    /**
     * Os empreendimentos em que a família pagou, antes desta revisão, mais do
     * que ela aprova: o valor pago a maior em aberto é o reconhecido numa
     * Finalização anterior mais o que esta revisão ainda precisa reconhecer.
     * O excesso de um pagamento da própria revisão (só possível sem pagamento
     * anterior, dentro de uma regra financeira) é divergência do pagamento,
     * justificada e aceita nele, e não entra aqui.
     *
     * @param  list<MeasurementRevisionPlanSetPosition>  $positions
     * @return list<MeasurementRevisionPlanSetPosition>
     */
    private function outstandingHistoricalOverpayments(array $positions): array
    {
        return array_values(array_filter(
            $positions,
            fn (MeasurementRevisionPlanSetPosition $position): bool => $position->hasOutstandingOverpayment()
                && ($position->hasUnacknowledgedOverpayment() || $position->hasPreviouslyAcknowledgedOverpayment()),
        ));
    }

    /**
     * @param  list<MeasurementRevisionPlanSetPosition>  $outstanding
     */
    private function revisionOverpaymentsContent(array $outstanding): HtmlString
    {
        return new HtmlString(
            '<p class="text-sm text-gray-600 dark:text-gray-300">Esta revisão aprova menos do que a família já pagou. O sistema não registra devolução, estorno nem compensação: os pagamentos ficam como estão, e o valor pago a maior ainda não reconhecido precisa de uma decisão financeira expressa.</p>'
            .$this->overpaymentItems($outstanding)
        );
    }

    /**
     * A família pagou mais do que esta revisão aprova, e uma Finalização
     * anterior já reconheceu tudo: não há decisão nova a registrar, e a
     * diferença continua em aberto.
     *
     * @param  list<MeasurementRevisionPlanSetPosition>  $outstanding
     */
    private function previouslyAcknowledgedOverpaymentsContent(array $outstanding): HtmlString
    {
        return new HtmlString(
            '<p class="text-sm text-gray-600 dark:text-gray-300">A família pagou mais do que esta revisão aprova, e esse valor já foi reconhecido na Finalização de uma revisão anterior: não há nova decisão financeira a registrar. O sistema não registra devolução, estorno nem compensação: o reconhecimento não regulariza a diferença, que continua em aberto.</p>'
            .$this->overpaymentItems($outstanding)
        );
    }

    /**
     * Um item por empreendimento, com a conta fechando: o valor pago a maior em
     * aberto é o reconhecido anteriormente (e em qual revisão) mais o que esta
     * revisão precisa reconhecer. Só o pagamento da própria revisão faria
     * sobrar alguma coisa nessa conta -- o domínio recusa pagamento enquanto
     * houver valor pago a maior em aberto --, e o que sobrar aparece à parte.
     *
     * @param  list<MeasurementRevisionPlanSetPosition>  $outstanding
     */
    private function overpaymentItems(array $outstanding): string
    {
        $originIds = collect($outstanding)
            ->flatMap(fn (MeasurementRevisionPlanSetPosition $position): array => $position->previouslyAcknowledgedByMeasurementIds)
            ->unique()
            ->values()
            ->all();
        $originLabels = $originIds === [] ? collect() : Measurement::query()
            ->whereKey($originIds)
            ->get(['id', 'revision_number'])
            ->mapWithKeys(fn (Measurement $origin): array => [(int) $origin->getKey() => $origin->revisionLabel()]);

        $items = collect($outstanding)
            ->map(function (MeasurementRevisionPlanSetPosition $position) use ($originLabels): string {
                $acknowledged = $position->previouslyAcknowledgedOverpaymentAmount ?? '0.00';
                $toAcknowledge = $position->unacknowledgedOverpaymentAmount ?? '0.00';
                $origins = collect($position->previouslyAcknowledgedByMeasurementIds)
                    ->map(fn (mixed $id): ?string => $originLabels->get((int) $id))
                    ->filter()
                    ->implode(', ');
                $ownExcess = bcsub(bcsub((string) $position->outstandingOverpaymentAmount, $acknowledged, 2), $toAcknowledge, 2);

                return sprintf(
                    '<li><span class="font-medium text-gray-950 dark:text-white">%s</span> · aprovado vigente %s · pago pela família %s · pago a maior em aberto %s (reconhecido anteriormente %s%s · a reconhecer nesta revisão %s%s)</li>',
                    e($position->label),
                    e(MeasurementFinancialReconciliationService::formatCurrency($position->revisedApprovedAmount)),
                    e(MeasurementFinancialReconciliationService::formatCurrency($position->familyPaidAmount)),
                    e(MeasurementFinancialReconciliationService::formatCurrency($position->outstandingOverpaymentAmount)),
                    e(MeasurementFinancialReconciliationService::formatCurrency($acknowledged)),
                    $origins === '' ? '' : e(", na {$origins}"),
                    e(MeasurementFinancialReconciliationService::formatCurrency($toAcknowledge)),
                    bccomp($ownExcess, '0', 2) > 0
                        ? e(' · pago a maior por pagamento desta revisão '.MeasurementFinancialReconciliationService::formatCurrency($ownExcess))
                        : '',
                );
            })
            ->implode('');

        return '<ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.$items.'</ul>';
    }

    /**
     * @param  list<MeasurementFinancialReconciliationLine>  $unpaid
     */
    private function unpaidPlanSetsContent(array $unpaid): HtmlString
    {
        $items = collect($unpaid)
            ->map(fn (MeasurementFinancialReconciliationLine $line): string => bccomp($line->historicalPaidAmount, '0', 2) > 0
                ? sprintf(
                    '<li><span class="font-medium text-gray-950 dark:text-white">%s</span> · valor esperado %s · pago antes da revisão %s · saldo em aberto %s</li>',
                    e($line->label),
                    e(MeasurementFinancialReconciliationService::formatCurrency($line->expectedAmount)),
                    e(MeasurementFinancialReconciliationService::formatCurrency($line->historicalPaidAmount)),
                    e(MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance)),
                )
                : sprintf(
                    '<li><span class="font-medium text-gray-950 dark:text-white">%s</span> · valor esperado %s</li>',
                    e($line->label),
                    e(MeasurementFinancialReconciliationService::formatCurrency($line->expectedAmount)),
                ))
            ->implode('');

        return new HtmlString(
            '<p class="text-sm text-gray-600 dark:text-gray-300">A Engenharia aprovou valor a pagar para estes empreendimentos e nenhum pagamento foi registrado nesta medição. Registre o pagamento ou justifique a ausência abaixo.</p>'
            .'<ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.$items.'</ul>'
        );
    }

    /**
     * Onde o empreendimento está antes desta medição: a pessoa vê quanto ainda
     * cabe até 100% antes de digitar, e não depois de recusada.
     *
     * Com medição do empreendimento em reanálise da Engenharia -- esta ou
     * outra da operação --, a contribuição que ela tinha aprovado antes está
     * fora do avanço reconhecido até a nova decisão da Engenharia: o bloco
     * mostra quanto e de quem, para a queda do avanço atual não parecer
     * regressão da obra. Sem reanálise, o bloco fica exatamente como era.
     *
     * @param  array{label: string, percent: string}|null  $previousRevision
     * @param  list<array{measurement_id: int, revision_label: string, reference_month: string|null, basis_points: int, plan_line_lineage_key: string|null, invalidated_at: CarbonImmutable|null}>  $reanalysis
     */
    private function physicalProgressContent(string $label, MeasurementPhysicalProgress $progress, ?string $versionLabel = null, ?array $previousRevision = null, array $reanalysis = []): HtmlString
    {
        $initial = MeasurementPhysicalProgress::format($progress->initialBasisPoints);

        if ($progress->initialReferenceDate !== null) {
            $initial .= ' em '.$progress->initialReferenceDate->format('d/m/Y');
        }

        $cells = [
            ['Avanço físico inicial', $initial],
            ['Medido no sistema', MeasurementPhysicalProgress::format($progress->measuredBasisPoints())],
            ['Avanço físico atual', MeasurementPhysicalProgress::format($progress->currentBasisPoints())],
            ['Máximo restante', MeasurementPhysicalProgress::format($progress->remainingBasisPoints())],
            ...($reanalysis === [] ? [] : [['Fora do avanço reconhecido (reanálise)', self::percentagePoints(array_sum(array_column($reanalysis, 'basis_points')))]]),
            ...($versionLabel === null ? [] : [['Versão do plano', $versionLabel]]),
            ...($previousRevision === null ? [] : [['Realizado aprovado na '.$previousRevision['label'], $previousRevision['percent']]]),
        ];

        $html = collect($cells)
            ->map(fn (array $cell): string => sprintf(
                '<div><dt class="text-xs text-gray-500 dark:text-gray-400">%s</dt><dd class="text-sm font-medium text-gray-950 dark:text-white">%s</dd></div>',
                e($cell[0]),
                e($cell[1]),
            ))
            ->implode('');

        return new HtmlString(sprintf(
            '<p class="text-sm font-medium text-gray-950 dark:text-white">%s</p><dl class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="%s">%s</dl>%s',
            e($label),
            e("Progresso físico de {$label}"),
            $html,
            $reanalysis === [] ? '' : sprintf(
                '<p class="mt-2 text-sm text-gray-600 dark:text-gray-300">%s</p>',
                e($this->reanalysisSentence($reanalysis)),
            ),
        ));
    }

    /**
     * Uma frase por medição em reanálise, com a competência e o dia em que o
     * registro aprovado foi invalidado (no fuso de negócio). Não promete que a
     * contribuição volta: a nova decisão da Engenharia pode ser outra.
     *
     * @param  list<array{measurement_id: int, revision_label: string, reference_month: string|null, basis_points: int, plan_line_lineage_key: string|null, invalidated_at: CarbonImmutable|null}>  $reanalysis
     */
    private function reanalysisSentence(array $reanalysis): string
    {
        return collect($reanalysis)
            ->map(fn (array $entry): string => sprintf(
                'Medição %s em reanálise da Engenharia: %s aprovados antes estão fora do avanço reconhecido%s.',
                $entry['revision_label'].($entry['reference_month'] === null ? '' : ' · '.$entry['reference_month']),
                self::percentagePoints((int) $entry['basis_points']),
                $entry['invalidated_at'] instanceof CarbonImmutable
                    ? ' (registro invalidado em '.BusinessTime::at($entry['invalidated_at'])->format('d/m/Y').')'
                    : '',
            ))
            ->push('Isso não indica, por si só, regressão física da obra.')
            ->implode(' ');
    }

    /**
     * Pontos percentuais no formato do avanço físico: "12,00 p.p.".
     */
    private static function percentagePoints(int $basisPoints): string
    {
        return str_replace('%', '', MeasurementPhysicalProgress::format($basisPoints)).' p.p.';
    }

    /**
     * @return array<int, list<array{measurement_id: int, revision_label: string, reference_month: string|null, basis_points: int, plan_line_lineage_key: string|null, invalidated_at: CarbonImmutable|null}>>
     */
    private function reanalysisContributions(): array
    {
        return $this->reanalysisContributionsByPlanSet ??= app(MeasurementPhysicalProgressService::class)
            ->reanalysisContributions((int) $this->record->operation_id);
    }

    /**
     * Na revisão, o realizado que a revisão anterior aprovou em cada
     * empreendimento -- o valor que esta corrige.
     *
     * @return array<int, array{label: string, percent: string}>
     */
    private function previousRevisionPercents(): array
    {
        if (! $this->record->isRevision()) {
            return [];
        }

        $previous = $this->record->previousRevision()->first(['id', 'revision_number', 'engineering_snapshot']);
        $snapshot = $previous?->engineering_snapshot;

        return collect(is_array($snapshot) ? ($snapshot['plan_sets'] ?? []) : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->mapWithKeys(fn (array $entry): array => [(int) ($entry['plan_set_id'] ?? 0) => [
                'label' => $previous->revisionLabel(),
                'percent' => MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($entry['realized_monthly_percent'] ?? '0')),
            ]])
            ->all();
    }

    /**
     * A versão do plano em que o arquivo deste empreendimento foi enviado, com
     * o Fundo de Obra dela -- a que a Engenharia aprova e a que vale para o
     * pagamento, mesmo que outra versão já esteja vigente.
     */
    private function capturedVersionLabel(int $planSetId): ?string
    {
        $version = $this->record->assets()
            ->where('plan_set_id', $planSetId)
            ->whereNotNull('plan_version_id')
            ->with('planVersion')
            ->first()
            ?->planVersion;

        if (! $version instanceof MeasurementPlanVersion) {
            return null;
        }

        return $version->label().(blank($version->construction_fund_amount)
            ? ''
            : ' · Fundo de Obra R$ '.MoneyFormatter::formatCurrencyForDisplay($version->construction_fund_amount));
    }

    /**
     * Developments (plan sets) covered by this measurement, derived from its
     * uploaded assets when available, otherwise from the operation's plan sets
     * that plan the competence -- pela versão que a regia no envio, como a
     * Engenharia exige ({@see MeasurementEngineeringService}).
     *
     * @return Collection<int, MeasurementPlanSet>
     */
    private function coveredPlanSets(): Collection
    {
        $fromAssets = $this->record->assets()
            ->with('planSet.construction')
            ->get()
            ->pluck('planSet')
            ->filter()
            ->unique('id')
            ->values();

        if ($fromAssets->isNotEmpty()) {
            return $fromAssets;
        }

        $competence = $this->record->reference_month;
        $measurementId = (int) $this->record->getKey();

        return $this->record->operation?->planSets()
            ->when(
                $competence === null,
                fn (Builder $plans): Builder => $plans->whereHas('activeVersion'),
                fn (Builder $plans): Builder => $plans->whereHas('lines', fn (Builder $lines): Builder => $lines
                    ->governingTheirCompetence($measurementId)
                    ->inCompetence($competence)),
            )
            ->with('construction')
            ->get() ?? collect();
    }

    /**
     * @return array<int, string>
     */
    private function planSetLabelMap(): array
    {
        return $this->coveredPlanSets()
            ->mapWithKeys(fn ($planSet): array => [
                $planSet->id => $planSet->construction?->development_name ?? $planSet->name,
            ])
            ->all();
    }

    /**
     * A recusa na Engenharia encerra a medição, e medição com pagamento
     * registrado só termina finalizada. A recusa na Gestão devolve a medição à
     * Engenharia, invalidando o registro aprovado -- que, na medição paga, é a
     * base do pagamento e não é reescrito: a correção dela é uma revisão formal.
     * Nos dois casos o botão continua à vista de quem decide a etapa, mas
     * desabilitado com o motivo do domínio: a pessoa lê, antes de tentar, o que
     * fazer em vez de recusar. O servidor não monta a ação desabilitada e o
     * domínio recusa de novo, sob lock.
     */
    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Recusar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalDescription(fn (): string => match (true) {
                $this->stage() <= MeasurementWorkflow::STAGE_ENGINEERING && $this->record->isRevision() && $this->record->isPendingRevision() => 'Recusar na Engenharia encerra esta revisão ('.$this->record->revisionLabel().'). A revisão anterior continua valendo, sem nenhuma alteração.',
                $this->stage() <= MeasurementWorkflow::STAGE_ENGINEERING => 'Recusar na Engenharia encerra a medição e notifica os responsáveis por recusa.',
                $this->stage() === self::MANAGEMENT_STAGE => 'A medição voltará para a Engenharia, e o registro aprovado por ela será invalidado.'
                    .($this->record->isEffectiveRevision() ? ' Até a nova aprovação da Engenharia, a contribuição física desta medição fica fora do avanço reconhecido.' : ''),
                default => 'A medição voltará para a etapa anterior para correção.',
            })
            ->visible(fn (): bool => $this->workflow()->canReject($this->record, $this->actor()))
            ->disabled(fn (): bool => $this->rejectionBlockReason() !== null)
            ->tooltip(fn (): ?string => $this->rejectionBlockReason())
            ->schema([
                Hidden::make('expected_stage')->default(fn (): int => $this->stage()),
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Textarea::make('notes')->label('Motivo da recusa')->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $terminal = $this->stage() <= MeasurementWorkflow::STAGE_ENGINEERING;
                    $closesRevision = $terminal && $this->record->isRevision() && $this->record->isPendingRevision();
                    $this->workflow()->reject(
                        $this->record,
                        $this->actor(),
                        $data['notes'],
                        expectedStage: (int) $data['expected_stage'],
                        expectedRevision: (int) $data['expected_revision'],
                    );
                    $this->notify(match (true) {
                        $closesRevision => 'Revisão recusada e encerrada. A revisão anterior continua valendo.',
                        $terminal => 'Medição recusada e encerrada.',
                        default => 'Medição devolvida para a etapa anterior.',
                    });
                });
            });
    }

    /**
     * Por que a recusa não pode acontecer agora, ou `null`: a recusa que
     * encerraria uma medição paga na Engenharia
     * ({@see MeasurementWorkflow::terminalRejectionBlockReason()}) ou a da
     * Gestão que devolveria à Engenharia o registro aprovado de uma medição
     * paga ({@see MeasurementWorkflow::engineeringReturnBlockReason()}). Nas
     * etapas Compliance e Pagamento a recusa só devolve à etapa anterior, sem
     * tocar a Engenharia.
     */
    private function rejectionBlockReason(): ?string
    {
        return $this->workflow()->terminalRejectionBlockReason($this->record)
            ?? ($this->stage() === self::MANAGEMENT_STAGE ? $this->workflow()->engineeringReturnBlockReason($this->record) : null);
    }

    private function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Pausar')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (): bool => $this->workflow()->canPause($this->record, $this->actor()))
            ->schema([
                Hidden::make('expected_stage')->default(fn (): int => $this->stage()),
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Textarea::make('reason')->label('Motivo da pausa')->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->workflow()->pause(
                        $this->record,
                        $this->actor(),
                        $data['reason'],
                        expectedStage: (int) $data['expected_stage'],
                        expectedRevision: (int) $data['expected_revision'],
                    );
                    $this->notify('Medição pausada.');
                });
            });
    }

    private function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Retomar')
            ->icon('heroicon-o-play-circle')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->workflow()->canResume($this->record, $this->actor()))
            ->schema([
                Hidden::make('expected_stage')->default(fn (): int => $this->stage()),
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Hidden::make('expected_pause_id')->default(fn (): ?int => $this->record->pauses()
                    ->whereNull('resumed_at')
                    ->latest('paused_at')
                    ->value('id')),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->workflow()->resume(
                        $this->record,
                        $this->actor(),
                        expectedStage: (int) $data['expected_stage'],
                        expectedRevision: (int) $data['expected_revision'],
                        expectedPauseId: (int) $data['expected_pause_id'],
                    );
                    $this->notify('Análise retomada.');
                });
            });
    }

    private function registerPaymentAction(): Action
    {
        return Action::make('registerPayment')
            ->label('Registrar Pagamento')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalWidth('2xl')
            ->visible(fn (): bool => $this->workflow()->canRegisterPayment($this->record, $this->actor()))
            ->schema(fn (): array => $this->registerPaymentSchema())
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $rows = collect($data['payments'] ?? [])
                        ->map(fn (array $row): array => [
                            'plan_set_id' => $row['plan_set_id'] ?? null,
                            'amount' => $row['amount'] ?? null,
                            'pay_date' => $data['pay_date'],
                            'method' => $data['method'] ?? null,
                            'notes' => $row['notes'] ?? null,
                            'financial_rule_id' => $row['financial_rule_id'] ?? null,
                            'financial_justification' => $row['financial_justification'] ?? null,
                            'financial_support' => $row['financial_support'] ?? null,
                        ])
                        ->all();

                    try {
                        $created = $this->workflow()->registerPayments(
                            $this->record,
                            $this->actor(),
                            $rows,
                            expectedRevision: (int) $data['expected_revision'],
                        );
                    } catch (ValidationException $exception) {
                        throw $this->onPaymentRepeaterItems($exception, $rows);
                    }

                    if ($created->isEmpty()) {
                        Notification::make()->warning()->title('Informe ao menos um valor de pagamento.')->send();

                        return;
                    }

                    $this->notify($created->count() > 1
                        ? "{$created->count()} pagamentos registrados. A etapa Pagamento ainda precisa ser aprovada."
                        : 'Pagamento registrado. A etapa Pagamento ainda precisa ser aprovada.');
                });
            });
    }

    /**
     * Leva os erros por linha do domínio para os itens reais do Repeater.
     *
     * O domínio numera as linhas com valor na ordem em que chegaram (0, 1...),
     * mas o Repeater identifica cada item por uma chave própria (UUID): com o
     * número, o erro não casava com campo nenhum e o envio parecia não fazer
     * nada. A posição original da linha é a posição do item no estado do
     * modal -- o Repeater não adiciona, não exclui e não reordena. Data e
     * método são campos únicos do modal, valem para todas as linhas e recebem
     * o erro de qualquer uma. O que ainda assim não casar vira notificação no
     * {@see self::guarded()}.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function onPaymentRepeaterItems(ValidationException $exception, array $rows): ValidationException
    {
        $positions = collect($rows)->filter(fn (array $row): bool => filled($row['amount'] ?? null))->keys()->all();
        $rawState = $this->getMountedActionSchema()?->getRawState();
        $itemKeys = array_keys(is_array($rawState) && is_array($rawState['payments'] ?? null) ? $rawState['payments'] : []);
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            if (! preg_match('/^payments\.(\d+)\.(.+)$/', (string) $field, $match)) {
                $errors[$field] = $messages;

                continue;
            }

            if (in_array($match[2], ['pay_date', 'method'], true)) {
                $errors[$match[2]] = $messages;

                continue;
            }

            $position = $positions[(int) $match[1]] ?? null;
            $itemKey = ($position === null) ? null : ($itemKeys[$position] ?? null);
            $errors[($itemKey === null) ? $field : "payments.{$itemKey}.{$match[2]}"] = $messages;
        }

        return ValidationException::withMessages($errors);
    }

    /**
     * @return array<int, Component>
     */
    private function registerPaymentSchema(): array
    {
        $labels = $this->planSetLabelMap();

        $rows = collect($labels)
            ->map(fn (string $label, int $planSetId): array => ['plan_set_id' => $planSetId])
            ->values()
            ->all();

        // Na original em que nada é devido, o pagamento não é o caminho: um
        // pagamento registrado não se altera nem se exclui, e fecharia para
        // sempre a conclusão sem desembolso.
        $nothingDue = ! $this->record->isRevision()
            && ! $this->record->payments()->exists()
            && app(MeasurementPaymentFinancialService::class)->amountDue($this->record)->isNothingDue();

        // Método e valor respondem no próprio campo, com o texto do campo: a
        // mesma regra no domínio só aparecia depois, numerada pela linha.
        return [
            ...($nothingDue ? [
                Placeholder::make('nothing_due')
                    ->hiddenLabel()
                    ->content(new HtmlString('<p class="text-sm text-amber-700 dark:text-amber-400">Nenhum pagamento devido: o valor aprovado pela Engenharia é zero em todos os empreendimentos. Para concluir a etapa sem pagamento, use "Concluir etapa sem desembolso". Um pagamento registrado não pode ser alterado nem excluído.</p>')),
            ] : []),
            Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
            // Data civil de Brasília, não o dia UTC: entre 21h e 24h o UTC já
            // está no dia seguinte, e a data de um pagamento registrado não muda
            // mais. Instância à meia-noite, não texto: o seletor guarda
            // `Y-m-d H:i:s` e preencheria a hora atual num texto, e o limite
            // `before_or_equal` de hoje recusaria o próprio dia. O domínio recusa
            // a data futura de novo ({@see MeasurementWorkflow::registerPayments()}).
            DatePicker::make('pay_date')
                ->label('Data do pagamento')
                ->required()
                ->default(fn (): CarbonImmutable => CarbonImmutable::parse(BusinessTime::dateString()))
                ->maxDate(fn (): string => BusinessTime::dateString())
                ->validationMessages([
                    'before_or_equal' => MeasurementWorkflow::FUTURE_PAY_DATE_REFUSAL,
                ])
                ->live(),
            TextInput::make('method')->label('Método')->placeholder('TED, PIX, Boleto...')->maxLength(255),
            Repeater::make('payments')
                ->label('Pagamento por empreendimento')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->default($rows)
                ->columns(2)
                ->itemLabel(fn (array $state): ?string => $labels[$state['plan_set_id'] ?? null] ?? null)
                ->schema([
                    Placeholder::make('reconciliation_reference')
                        ->label('Referência desta medição')
                        ->columnSpanFull()
                        ->content(fn (Get $get): HtmlString => $this->paymentReferenceContent($get('plan_set_id'))),
                    Select::make('plan_set_id')
                        ->label('Empreendimento')
                        ->options($labels)
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('amount')
                        ->label('Valor deste pagamento')
                        ->prefix('R$')
                        ->live(onBlur: true)
                        ->mask(RawJs::make('$money($input, \',\', \'.\')'))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
                        ->rules(['numeric', 'gt:0'])
                        ->validationMessages([
                            'gt' => 'O valor do pagamento deve ser maior que zero.',
                        ])
                        ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state)),
                    Placeholder::make('reconciliation_divergence')
                        ->label('Conciliação')
                        ->columnSpanFull()
                        ->content(fn (Get $get): HtmlString => $this->paymentDivergenceContent($get('plan_set_id'), $get('amount'))),
                    ...$this->financialExceptionFields(
                        fn (Get $get): int => (int) $get('plan_set_id'),
                        fn (Get $get): string => (string) ($get('../../pay_date') ?? BusinessTime::dateString()),
                    ),
                    Textarea::make('notes')->label('Observações')->rows(2)->columnSpanFull(),
                ]),
        ];
    }

    /**
     * Contexto financeiro do empreendimento antes do campo de valor.
     *
     * A referência é apenas exibida: o valor continua sendo informado pela
     * pessoa, sem preenchimento silencioso, para não induzir aceite cego.
     */
    private function paymentReferenceContent(mixed $planSetId): HtmlString
    {
        $line = $this->reconciliationLine($planSetId);

        if (! $line instanceof MeasurementFinancialReconciliationLine) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Empreendimento fora do contexto aprovado pela Engenharia.</span>');
        }

        if (! $line->hasFinancialReference()) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Sem fundo de obra registrado no snapshot da Engenharia — não há valor esperado para comparar.</span>');
        }

        $cells = [
            ['Fundo de obra', MeasurementFinancialReconciliationService::formatCurrency($line->fundAmount)],
            ['Realizado no mês', MeasurementFinancialReconciliationService::formatPercent($line->realizedMonthlyPercent)],
            ['Valor esperado', MeasurementFinancialReconciliationService::formatCurrency($line->expectedAmount)],
            // Na revisão, o que as revisões anteriores já pagaram não se paga de novo.
            ...(bccomp($line->historicalPaidAmount, '0', 2) > 0
                ? [['Pago antes da revisão', MeasurementFinancialReconciliationService::formatCurrency($line->historicalPaidAmount)]]
                : []),
            ['Já registrado', MeasurementFinancialReconciliationService::formatCurrency($line->registeredAmount)],
            ['Saldo esperado', MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance)],
        ];

        $html = collect($cells)
            ->map(fn (array $cell): string => sprintf(
                '<div><dt class="text-xs text-gray-500 dark:text-gray-400">%s</dt><dd class="text-sm font-medium text-gray-950 dark:text-white">%s</dd></div>',
                e($cell[0]),
                e($cell[1]),
            ))
            ->implode('');

        return new HtmlString('<dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">'.$html.'</dl>');
    }

    /**
     * Divergência entre o valor informado e o saldo esperado.
     *
     * A divergência não impede o registro: exige a justificativa do pagamento
     * e o aceite expresso do Finalizador. O texto é neutro porque pagar a menos
     * ou a mais pode ter motivo legítimo.
     */
    private function paymentDivergenceContent(mixed $planSetId, mixed $amount): HtmlString
    {
        $line = $this->reconciliationLine($planSetId, $amount);

        if (! $line instanceof MeasurementFinancialReconciliationLine || ! $line->hasFinancialReference()) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">—</span>');
        }

        // Antes de a pessoa digitar, o valor informado é zero e a comparação
        // acusaria o saldo inteiro como divergência: alarme sem informação.
        if (blank($amount)) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Informe o valor para comparar com o saldo esperado.</span>');
        }

        $colors = [
            'success' => 'text-green-600 dark:text-green-400',
            'warning' => 'text-amber-600 dark:text-amber-400',
            'gray' => 'text-gray-500 dark:text-gray-400',
        ];

        return new HtmlString(sprintf(
            '<span class="text-sm font-medium %s">%s</span>',
            $colors[$line->status->color()] ?? $colors['gray'],
            e($this->describeDivergence($line)),
        ));
    }

    private function reconciliationLine(mixed $planSetId, mixed $amount = null): ?MeasurementFinancialReconciliationLine
    {
        if (blank($planSetId)) {
            return null;
        }

        $planSetId = (int) $planSetId;
        $entered = blank($amount) ? [] : [$planSetId => $amount];

        return app(MeasurementFinancialReconciliationService::class)
            ->forMeasurement($this->record, $entered)
            ->line($planSetId);
    }

    private function describeDivergence(MeasurementFinancialReconciliationLine $line): string
    {
        $divergence = MeasurementFinancialReconciliationService::formatCurrency(ltrim((string) $line->divergenceAmount, '-'));

        return match ($line->status) {
            MeasurementReconciliationStatus::Matched => 'Conciliado — o valor informado corresponde ao saldo esperado.',
            MeasurementReconciliationStatus::Under => "Divergência para menos — {$divergence} abaixo do saldo esperado.",
            MeasurementReconciliationStatus::Over => "Divergência para mais — {$divergence} acima do saldo esperado.",
            MeasurementReconciliationStatus::ReferenceUnavailable => 'Sem referência financeira para comparar.',
        };
    }

    /** @return array<Component> */
    private function financialExceptionFields(Closure $planSetId, Closure $paymentDate): array
    {
        return [
            Select::make('financial_rule_id')->label('Regra financeira aplicável')->columnSpanFull()->searchable()
                ->placeholder('Exceção sem regra cadastrada')
                ->helperText('Somente regras da emissão e obra aprovadas, vigentes na data do pagamento. Selecionar uma regra não dispensa a conferência do Finalizador.')
                ->options(fn (Get $get): array => app(MeasurementFinancialRuleService::class)
                    ->availableFor($this->record, $planSetId($get), $paymentDate($get))->orderBy('name')->get()
                    ->mapWithKeys(fn ($rule): array => [$rule->id => $rule->name.' · versão '.$rule->version])->all()),
            Textarea::make('financial_justification')->label('Justificativa da divergência')->rows(3)->maxLength(5000)
                ->helperText('Obrigatória quando o pagamento for diferente do saldo esperado, inclusive nos casos previstos por uma regra.')->columnSpanFull(),
            // O campo só recebe arquivo enviado agora; um caminho escrito no
            // estado do modal é recusado no próprio campo, em vez de chegar ao
            // domínio no lugar do arquivo.
            FileUpload::make('financial_support')->label('Documento de suporte')->storeFiles(false)
                ->preventFilePathTampering()
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)
                ->helperText('PDF, JPG ou PNG de até 10 MB. Obrigatório quando exigido pela regra.')->columnSpanFull(),
        ];
    }

    private function reassessPaymentAction(): Action
    {
        return Action::make('reassessPayment')->label('Reavaliar enquadramento')->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => $this->workflow()->canRegisterPayment($this->record, $this->actor()) && $this->record->payments()->exists())
            ->modalDescription('Atualize a regra e a justificativa de um pagamento existente após uma devolução. O valor do pagamento permanece preservado.')
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Select::make('payment_id')->label('Pagamento')->required()->live()
                    ->options(fn (): array => $this->record->payments()->get()->mapWithKeys(fn (MeasurementPayment $payment): array => [
                        $payment->id => '#'.$payment->id.' · '.MeasurementFinancialReconciliationService::formatCurrency($payment->amount),
                    ])->all())
                    ->afterStateUpdated(function (Set $set): void {
                        $set('financial_rule_id', null);
                        $set('financial_justification', null);
                        $set('financial_support', null);
                    }),
                ...$this->financialExceptionFields(
                    fn (Get $get): int => (int) $this->record->payments()->find($get('payment_id'))?->plan_set_id,
                    fn (Get $get): string => $this->record->payments()->find($get('payment_id'))?->pay_date?->toDateString() ?? BusinessTime::dateString(),
                ),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $payment = $this->record->payments()->findOrFail($data['payment_id']);
                    $this->workflow()->reassessPayment($payment, $this->actor(), $data, (int) $data['expected_revision']);
                    $this->notify('Enquadramento financeiro atualizado para conferência do Finalizador.');
                });
            });
    }

    /**
     * Comprovante enviado na Finalização aberta, ou corrigido depois do
     * encerramento -- a medição finalizada, ou a paga que uma revisão
     * substituiu antes da Finalização (o fluxo dela foi encerrado pela revisão
     * que passou a valer, e os pagamentos continuam nela como histórico). O
     * serviço decide onde cada um vale ({@see MeasurementReceiptEvidenceService::canUpload()})
     * e recusa a ação trocada.
     */
    private function attachReceiptAction(bool $postFinalization = false): Action
    {
        return Action::make($postFinalization ? 'correctReceipt' : 'attachReceipt')
            ->label($postFinalization ? 'Corrigir comprovante' : 'Anexar / substituir comprovante')
            ->icon('heroicon-o-paper-clip')
            ->color('info')
            ->modalWidth('2xl')
            ->modalDescription(fn (): string => match (true) {
                ! $postFinalization => 'O arquivo anterior será preservado. Cada envio cria uma nova versão pendente de conferência.',
                $this->record->status === 'superseded' => 'O encerramento desta medição pela revisão que passou a valer será preservado. A nova versão ficará pendente de conferência documental.',
                default => 'A finalização original será preservada. A nova versão ficará pendente de conferência documental.',
            })
            ->visible(fn (): bool => in_array($this->record->status, ['finalized', 'superseded'], true) === $postFinalization
                && app(MeasurementReceiptEvidenceService::class)->canUpload($this->record, $this->actor())
                && $this->record->payments()->exists())
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Hidden::make('expected_evidence_id'),
                Select::make('payment_id')
                    ->label('Pagamento')
                    ->options(fn (): array => $this->record->payments()->orderBy('id')->get()
                        ->mapWithKeys(fn (MeasurementPayment $payment): array => [
                            $payment->getKey() => sprintf('#%d · %s · R$ %s · %s', $payment->getKey(), $payment->pay_date?->format('d/m/Y'), number_format((float) $payment->amount, 2, ',', '.'), $payment->method ?: 'Método não informado'),
                        ])->all())
                    ->required()->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $payment = $this->record->payments()->with('currentReceiptEvidence')->find($state);
                        $set('expected_evidence_id', $payment?->currentReceiptEvidence?->getKey());
                    }),
                // Sem a trava, uma string escrita no estado do modal chegava ao
                // serviço no lugar do arquivo e virava erro de servidor (TypeError).
                FileUpload::make('receipt')
                    ->label('Comprovante')->required()->storeFiles(false)
                    ->preventFilePathTampering()
                    ->acceptedFileTypes((array) config('uploads.measurement_receipt.allowed_mimes', []))
                    ->maxSize((int) config('uploads.measurement_receipt.max_kb', 10240)),
                Textarea::make('correction_reason')
                    ->label('Motivo da correção / substituição')
                    ->required(fn (Get $get): bool => $postFinalization || filled($get('expected_evidence_id')))
                    ->maxLength(5000)->rows(3),
            ])
            ->action(function (array $data) use ($postFinalization): void {
                $this->guarded(function () use ($data, $postFinalization): void {
                    $payment = $this->record->payments()->findOrFail($data['payment_id']);
                    $expectedEvidenceId = filled($data['expected_evidence_id'] ?? null) ? (int) $data['expected_evidence_id'] : null;
                    $service = app(MeasurementReceiptEvidenceService::class);

                    if ($postFinalization) {
                        $service->correctFinalizedReceipt($payment, $this->actor(), $data['receipt'], $data['correction_reason'], $expectedEvidenceId);
                    } else {
                        $service->upload($payment, $this->actor(), $data['receipt'], $expectedEvidenceId, $data['correction_reason'] ?? null, (int) $data['expected_revision']);
                    }

                    $this->notify('Nova versão anexada. Aguardando conferência documental do Finalizador.');
                });
            });
    }

    private function reviewReceiptAction(): Action
    {
        return Action::make('reviewReceipt')
            ->label('Conferir comprovante')
            ->icon('heroicon-o-document-check')
            ->modalWidth('2xl')->requiresConfirmation()
            ->visible(fn (): bool => app(MeasurementReceiptEvidenceService::class)->canReview($this->record, $this->actor())
                && $this->pendingReceiptEvidences()->isNotEmpty())
            ->schema([
                Select::make('evidence_id')->label('Versão para conferência')->required()->live()
                    ->options(fn (): array => $this->pendingReceiptEvidences()
                        ->mapWithKeys(fn (MeasurementPaymentReceiptEvidence $evidence): array => [
                            $evidence->getKey() => sprintf('Pagamento #%d · v%d · %s', $evidence->measurement_payment_id, $evidence->version, $evidence->original_filename ?? 'Nome original não registrado no fluxo legado'),
                        ])->all())
                    ->afterStateUpdated(fn (Set $set) => $set('confirmed', false)),
                Placeholder::make('evidence_context')->label('Conferência documental')
                    ->content(function (Get $get): View {
                        $evidence = $this->pendingReceiptEvidences()->firstWhere('id', (int) $get('evidence_id'));

                        return view('filament.infolists.measurement-receipt-review', compact('evidence'));
                    }),
                Select::make('decision')->label('Decisão documental')->required()->live()
                    ->options(['approved' => 'Aprovar comprovante', 'rejected' => 'Rejeitar comprovante']),
                Textarea::make('notes')->label('Observação (opcional)')->maxLength(5000)->rows(2),
                Textarea::make('rejection_reason')->label('Motivo da rejeição')->maxLength(5000)->rows(3)
                    ->visible(fn (Get $get): bool => $get('decision') === 'rejected')
                    ->required(fn (Get $get): bool => $get('decision') === 'rejected'),
                Checkbox::make('confirmed')
                    ->label('Confirmo que conferi o comprovante correspondente a este pagamento.')
                    ->accepted()->required(),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $evidence = MeasurementPaymentReceiptEvidence::query()
                        ->whereHas('payment', fn ($query) => $query->where('measurement_id', $this->record->getKey()))
                        ->findOrFail($data['evidence_id']);
                    app(MeasurementReceiptEvidenceService::class)->review(
                        $evidence,
                        $this->actor(),
                        MeasurementReceiptReviewStatus::from($data['decision']),
                        (bool) $data['confirmed'],
                        $data['notes'] ?? null,
                        $data['rejection_reason'] ?? null,
                    );
                    $this->notify('Decisão documental registrada para esta versão.');
                });
            });
    }

    /** @return Collection<int, MeasurementPaymentReceiptEvidence> */
    private function pendingReceiptEvidences(): Collection
    {
        return $this->record->payments()->with(['currentReceiptEvidence.uploadedByUser'])->get()
            ->map(function (MeasurementPayment $payment): ?MeasurementPaymentReceiptEvidence {
                $evidence = $payment->currentReceiptEvidence;
                $evidence?->setRelation('payment', $payment);

                return $evidence;
            })->filter(fn (?MeasurementPaymentReceiptEvidence $evidence): bool => $evidence?->review_status === MeasurementReceiptReviewStatus::Pending)->values();
    }

    private function finalizeAction(): Action
    {
        return Action::make('finalize')
            ->label('Finalizar')
            ->icon('heroicon-o-flag')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Finalizar medição')
            ->modalDescription(fn (): string => 'A finalização do workflow será preservada. Correções posteriores de comprovantes exigirão nova versão e conferência documental.'
                .($this->revisionLeavesOpenDifference() ? ' Finalizar não regulariza diferença financeira em aberto.' : ''))
            ->visible(fn (): bool => $this->workflow()->canFinalize($this->record, $this->actor()))
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Hidden::make('expected_status')->default(fn (): string => (string) $this->record->status),
                Placeholder::make('financial_review')->label('Conferência financeira')
                    ->content(fn (): View => view('filament.infolists.measurement-financial-assessments', [
                        'measurement' => $this->record,
                        'noDisbursementDecisionShownApart' => $this->workflow()->requiresNoDisbursementAcknowledgment($this->record),
                    ])),
                Checkbox::make('accept_financial_exceptions')
                    ->label('Li as regras, justificativas e documentos e aceito expressamente as divergências financeiras.')
                    ->helperText(fn (): ?string => $this->revisionLeavesOpenDifference()
                        ? 'O aceite reconhece a diferença: não registra devolução nem a regulariza, e ela continua em aberto depois da Finalização.'
                        : null)
                    ->default(false)
                    ->visible(fn (): bool => app(MeasurementPaymentFinancialService::class)->requiresAcceptance($this->record))
                    ->accepted(fn (): bool => app(MeasurementPaymentFinancialService::class)->requiresAcceptance($this->record))
                    ->validationMessages([
                        'accepted' => fn (): string => $this->financialAcceptanceRefusal(),
                    ]),
                // A medição original sem pagamento chega aqui pela decisão sem
                // desembolso da etapa Pagamento: o Finalizador lê a decisão e
                // confirma a ciência, que o domínio exige e grava na trilha.
                Placeholder::make('no_disbursement_decision')
                    ->label('Decisão de pagamento sem desembolso')
                    ->visible(fn (): bool => $this->workflow()->requiresNoDisbursementAcknowledgment($this->record))
                    ->content(fn (): HtmlString => $this->noDisbursementDecisionContent()),
                Checkbox::make('acknowledge_no_disbursement')
                    ->label('Ciência da ausência de pagamento: confirmo que esta medição não tem pagamento devido, que nenhum pagamento bancário foi registrado e que conferi a justificativa da etapa Pagamento.')
                    ->helperText('A ciência não é pagamento, devolução, estorno nem regularização financeira: registra a decisão de que não há desembolso devido.')
                    ->default(false)
                    ->visible(fn (): bool => $this->workflow()->requiresNoDisbursementAcknowledgment($this->record))
                    ->accepted(fn (): bool => $this->workflow()->requiresNoDisbursementAcknowledgment($this->record))
                    ->validationMessages([
                        'accepted' => MeasurementWorkflow::NO_DISBURSEMENT_ACKNOWLEDGMENT_REQUIRED,
                    ]),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->workflow()->finalize(
                        $this->record,
                        $this->actor(),
                        expectedRevision: (int) $data['expected_revision'],
                        expectedStatus: (string) $data['expected_status'],
                        acceptFinancialExceptions: (bool) ($data['accept_financial_exceptions'] ?? false),
                        acknowledgeNoDisbursement: (bool) ($data['acknowledge_no_disbursement'] ?? false),
                    );
                    $this->notify('Medição finalizada.');
                });
            });
    }

    /**
     * A decisão sem desembolso da etapa Pagamento como o Finalizador a confere:
     * a justificativa, quem decidiu e quando, e a posição de cada
     * empreendimento. Sem a decisão na trilha, diz o caminho -- o domínio
     * recusa a Finalização do mesmo jeito.
     */
    private function noDisbursementDecisionContent(): HtmlString
    {
        $decision = $this->workflow()->noDisbursementDecision($this->record);

        if ($decision === null) {
            return new HtmlString('<p class="text-sm text-danger-600 dark:text-danger-400">'.e(MeasurementWorkflow::NO_DISBURSEMENT_DECISION_MISSING).'</p>');
        }

        $decidedBy = $decision['decided_by'] === null ? null : User::query()->whereKey($decision['decided_by'])->value('name');
        $decidedAt = $decision['decided_at'] === null ? null : BusinessTime::at(CarbonImmutable::parse($decision['decided_at']))->format('d/m/Y H:i');

        return new HtmlString(
            '<p class="text-sm text-gray-600 dark:text-gray-300">Medição sem desembolso financeiro: nenhum pagamento é devido e nenhum pagamento foi registrado.</p>'
            .'<p class="mt-2 text-sm text-gray-700 dark:text-gray-200"><span class="font-medium text-gray-950 dark:text-white">Justificativa:</span> '.e($decision['justification']).'</p>'
            .'<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">'.e(collect([
                $decidedBy === null ? null : 'Decidida por '.$decidedBy,
                $decidedAt === null ? null : 'em '.$decidedAt,
            ])->filter()->implode(' ')).'</p>'
            .$this->amountDueContent(app(MeasurementPaymentFinancialService::class)->amountDue($this->record))->toHtml()
        );
    }

    /**
     * A frase com que a Finalização recusa a falta do aceite expresso.
     *
     * A regra `accepted` do campo responde antes do domínio, e a mensagem
     * padrão montava o rótulo inteiro do checkbox numa frase sem sentido, sem
     * dizer o que estava sendo aceito. Com empreendimento sem pagamento, a
     * frase nomeia cada um com o valor esperado, como a do domínio
     * ({@see MeasurementPaymentFinancialService::acceptUnpaidPlanSetsForFinalization()});
     * senão, é a das divergências
     * ({@see MeasurementPaymentFinancialService::acceptForFinalization()}).
     */
    private function financialAcceptanceRefusal(): string
    {
        $financial = app(MeasurementPaymentFinancialService::class);
        $unpaid = $financial->unpaidRequiredPlanSets($this->record);

        if ($unpaid !== []) {
            return 'Confirme expressamente o aceite da ausência de pagamento justificada na etapa Pagamento: '.$financial->describeUnpaidPlanSets($unpaid).'.';
        }

        $overpayments = $this->record->isRevision() ? $this->revisions()->unacknowledgedOverpayments($this->record) : [];

        if ($overpayments !== []) {
            return 'Confirme expressamente o aceite do valor pago a maior desta revisão ainda não reconhecido, que continua em aberto, sem devolução registrada no sistema: '.$this->revisions()->describeOverpayments($overpayments).'.';
        }

        return 'Confirme expressamente o aceite das divergências e justificativas financeiras.';
    }

    /**
     * A revisão deixa diferença financeira em aberto depois da Finalização?
     * Valor pago a maior (reconhecido agora ou antes), saldo a pagar sem
     * pagamento, ou pagamento da família sem Fundo de Obra com que comparar --
     * as posições que a Finalização registra como exceção sem regularização.
     * Finalizar e aceitar reconhecem; nada disso regulariza.
     */
    private function revisionLeavesOpenDifference(): bool
    {
        return collect($this->revisions()->positions($this->record))->contains(
            fn (MeasurementRevisionPlanSetPosition $position): bool => $position->hasOutstandingOverpayment()
                || $position->hasRemainingPayable()
                || $position->isExposureUnmeasurable(),
        );
    }

    /**
     * Devolve a medição da Finalização a uma etapa anterior. A medição paga
     * não volta à Engenharia ({@see MeasurementWorkflow::engineeringReturnBlockReason()}):
     * a opção fica desabilitada, com o caminho -- a revisão formal -- abaixo do
     * campo; o Select recusa a opção desabilitada no servidor, com o motivo do
     * domínio, e o domínio recusa de novo sob o lock.
     */
    private function returnToStageAction(): Action
    {
        return Action::make('returnToStage')
            ->label('Devolver para Etapa')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => $this->workflow()->canReturnFromFinalization($this->record, $this->actor()))
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Hidden::make('expected_status')->default(fn (): string => (string) $this->record->status),
                Select::make('target_stage')
                    ->label('Etapa de destino')
                    ->options([
                        1 => 'Etapa 1 — Engenharia',
                        2 => 'Etapa 2 — Gestão',
                        3 => 'Etapa 3 — Compliance',
                        MeasurementWorkflow::STAGE_PAYMENT => 'Etapa 4 — Pagamento',
                    ])
                    ->disableOptionWhen(fn (mixed $value): bool => (int) $value === self::ENGINEERING_STAGE
                        && $this->workflow()->engineeringReturnBlockReason($this->record) !== null)
                    ->helperText(fn (): ?string => $this->workflow()->engineeringReturnBlockReason($this->record) === null
                        ? null
                        : 'Com pagamento registrado, a devolução não vai até a Engenharia: a correção dos dados aprovados é uma revisão formal (Criar revisão).')
                    ->validationMessages([
                        'in' => fn (mixed $state): string => ((int) $state === self::ENGINEERING_STAGE
                            ? $this->workflow()->engineeringReturnBlockReason($this->record)
                            : null) ?? 'Selecione uma etapa de destino válida.',
                    ])
                    ->required(),
                Textarea::make('reason')->label('Motivo')->required()->rows(2),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->workflow()->returnToStage(
                        $this->record,
                        $this->actor(),
                        (int) $data['target_stage'],
                        $data['reason'],
                        expectedRevision: (int) $data['expected_revision'],
                        expectedStatus: (string) $data['expected_status'],
                    );
                    $this->notify('Medição devolvida para a etapa selecionada.');
                });
            });
    }
}
