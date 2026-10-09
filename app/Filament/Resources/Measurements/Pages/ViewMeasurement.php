<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Concerns\MoneyFormatter;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\DTOs\Measurements\MeasurementPhysicalProgress;
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
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementFinancialRuleService;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementWorkflow;
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
        $statusLabel = Measurement::STATUS_OPTIONS[$this->record->status] ?? (string) $this->record->status;
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

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
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
        ];
    }

    private function workflow(): MeasurementWorkflow
    {
        return app(MeasurementWorkflow::class);
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
            ->visible(fn (): bool => $this->workflow()->canApprove($this->record, $this->actor()))
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

        $physicalProgress = app(MeasurementPhysicalProgressService::class)
            ->forOperation((int) $this->record->operation_id, (int) $this->record->getKey());

        // Sem preenchimento: a linha guarda o valor de uma aprovação que pode ter
        // deixado de valer, e com 0% aceito um padrão viraria aprovação silenciosa.
        $fields = $planSets->flatMap(function ($planSet) use ($physicalProgress): array {
            $label = $planSet->construction?->development_name ?? $planSet->name;
            $progress = $physicalProgress[(int) $planSet->id]
                ?? app(MeasurementPhysicalProgressService::class)->forPlanSet($planSet, (int) $this->record->getKey());

            return [
                Placeholder::make("physical_progress_context.{$planSet->id}")
                    ->hiddenLabel()
                    ->content($this->physicalProgressContent($label, $progress, $this->capturedVersionLabel((int) $planSet->id))),
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
                        'min' => 'O percentual realizado não pode ser negativo. Para corrigir uma medição já aprovada, devolva-a à Engenharia.',
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
     * @return array<int, Component>
     */
    private function paymentStageApprovalFields(): array
    {
        if (! $this->workflow()->hasValidPayment($this->record)) {
            return [
                Placeholder::make('payment_required')
                    ->hiddenLabel()
                    ->content(new HtmlString('<p class="text-sm text-gray-600 dark:text-gray-300">'.e(self::PAYMENT_REQUIRED_MESSAGE).'</p>')),
            ];
        }

        $financial = app(MeasurementPaymentFinancialService::class);
        $unpaid = $financial->unpaidRequiredPlanSets($this->record);

        if ($unpaid === []) {
            return [Textarea::make('notes')->label('Comentário (opcional)')->rows(3)];
        }

        return [
            Placeholder::make('unpaid_plan_sets')
                ->label('Empreendimentos sem pagamento nesta competência')
                ->content($this->unpaidPlanSetsContent($unpaid)),
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
     * @param  list<MeasurementFinancialReconciliationLine>  $unpaid
     */
    private function unpaidPlanSetsContent(array $unpaid): HtmlString
    {
        $items = collect($unpaid)
            ->map(fn (MeasurementFinancialReconciliationLine $line): string => sprintf(
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
     */
    private function physicalProgressContent(string $label, MeasurementPhysicalProgress $progress, ?string $versionLabel = null): HtmlString
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
            ...($versionLabel === null ? [] : [['Versão do plano', $versionLabel]]),
        ];

        $html = collect($cells)
            ->map(fn (array $cell): string => sprintf(
                '<div><dt class="text-xs text-gray-500 dark:text-gray-400">%s</dt><dd class="text-sm font-medium text-gray-950 dark:text-white">%s</dd></div>',
                e($cell[0]),
                e($cell[1]),
            ))
            ->implode('');

        return new HtmlString(sprintf(
            '<p class="text-sm font-medium text-gray-950 dark:text-white">%s</p><dl class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="%s">%s</dl>',
            e($label),
            e("Progresso físico de {$label}"),
            $html,
        ));
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
     * registrado só termina finalizada. O botão continua à vista de quem decide
     * a etapa, mas desabilitado com o motivo do domínio: a pessoa lê, antes de
     * tentar, por que precisa corrigir e aprovar em vez de recusar. O servidor
     * não monta a ação desabilitada e o domínio recusa de novo, sob lock.
     */
    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Recusar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalDescription(fn (): string => $this->stage() <= MeasurementWorkflow::STAGE_ENGINEERING
                ? 'Recusar na Engenharia encerra a medição e notifica os responsáveis por recusa.'
                : 'A medição voltará para a etapa anterior para correção.')
            ->visible(fn (): bool => $this->workflow()->canReject($this->record, $this->actor()))
            ->disabled(fn (): bool => $this->workflow()->terminalRejectionBlockReason($this->record) !== null)
            ->tooltip(fn (): ?string => $this->workflow()->terminalRejectionBlockReason($this->record))
            ->schema([
                Hidden::make('expected_stage')->default(fn (): int => $this->stage()),
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Textarea::make('notes')->label('Motivo da recusa')->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $terminal = $this->stage() <= MeasurementWorkflow::STAGE_ENGINEERING;
                    $this->workflow()->reject(
                        $this->record,
                        $this->actor(),
                        $data['notes'],
                        expectedStage: (int) $data['expected_stage'],
                        expectedRevision: (int) $data['expected_revision'],
                    );
                    $this->notify($terminal ? 'Medição recusada e encerrada.' : 'Medição devolvida para a etapa anterior.');
                });
            });
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

        // Método e valor respondem no próprio campo, com o texto do campo: a
        // mesma regra no domínio só aparecia depois, numerada pela linha.
        return [
            Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
            DatePicker::make('pay_date')->label('Data do pagamento')->required()->default(now())->live(),
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
                        fn (Get $get): string => (string) ($get('../../pay_date') ?? now()->toDateString()),
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
                    fn (Get $get): string => $this->record->payments()->find($get('payment_id'))?->pay_date?->toDateString() ?? now()->toDateString(),
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

    private function attachReceiptAction(bool $postFinalization = false): Action
    {
        return Action::make($postFinalization ? 'correctReceipt' : 'attachReceipt')
            ->label($postFinalization ? 'Corrigir comprovante' : 'Anexar / substituir comprovante')
            ->icon('heroicon-o-paper-clip')
            ->color('info')
            ->modalWidth('2xl')
            ->modalDescription($postFinalization
                ? 'A finalização original será preservada. A nova versão ficará pendente de conferência documental.'
                : 'O arquivo anterior será preservado. Cada envio cria uma nova versão pendente de conferência.')
            ->visible(fn (): bool => ($this->record->status === 'finalized') === $postFinalization
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
            ->modalDescription('A finalização do workflow será preservada. Correções posteriores de comprovantes exigirão nova versão e conferência documental.')
            ->visible(fn (): bool => $this->workflow()->canFinalize($this->record, $this->actor()))
            ->schema([
                Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
                Hidden::make('expected_status')->default(fn (): string => (string) $this->record->status),
                Placeholder::make('financial_review')->label('Conferência financeira')
                    ->content(fn (): View => view('filament.infolists.measurement-financial-assessments', ['measurement' => $this->record])),
                Checkbox::make('accept_financial_exceptions')
                    ->label('Li as regras, justificativas e documentos e aceito expressamente as divergências financeiras.')
                    ->default(false)
                    ->visible(fn (): bool => app(MeasurementPaymentFinancialService::class)->requiresAcceptance($this->record))
                    ->accepted(fn (): bool => app(MeasurementPaymentFinancialService::class)->requiresAcceptance($this->record))
                    ->validationMessages([
                        'accepted' => fn (): string => $this->financialAcceptanceRefusal(),
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
                    );
                    $this->notify('Medição finalizada.');
                });
            });
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

        return 'Confirme expressamente o aceite das divergências e justificativas financeiras.';
    }

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
