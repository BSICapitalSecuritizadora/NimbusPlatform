<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Concerns\MoneyFormatter;
use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementReconciliationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementPlanSet;
use App\Models\User;
use App\Services\MeasurementFinancialReconciliationService;
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
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class ViewMeasurement extends ViewRecord
{
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
     * Executa a ação do fluxo devolvendo a recusa como mensagem, não como erro.
     *
     * `MeasurementWorkflowException` já carrega texto de operador -- "A etapa
     * desta medição foi alterada por outra ação. Atualize a página." é a proteção
     * contra submissão obsoleta da P0 falando. Só que ninguém a lia: a exceção
     * subia sem tratamento e a pessoa via um erro genérico da página, sem
     * entender que outra pessoa tinha decidido a etapa primeiro.
     *
     * A recusa é do domínio e não é falha da aplicação, então a página não
     * quebra: notifica, recarrega o registro para a tela refletir o estado que
     * passou a valer, e interrompe a ação.
     */
    private function guarded(Closure $operation): void
    {
        try {
            $operation();
        } catch (MeasurementWorkflowException $exception) {
            Notification::make()
                ->danger()
                ->title('Ação não concluída.')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            $this->record->refresh();

            throw new Halt;
        }
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
            Textarea::make('notes')->label('Comentário (opcional)')->rows(3),
        ];

        if ($this->record->current_stage !== self::ENGINEERING_STAGE) {
            return $schema;
        }

        $planSets = $this->coveredPlanSets();

        if ($planSets->isEmpty()) {
            return $schema;
        }

        $referenceMonth = $this->record->reference_month;

        $fields = $planSets->map(function ($planSet) use ($referenceMonth): TextInput {
            $label = $planSet->construction?->development_name ?? $planSet->name;

            $currentMonthly = $referenceMonth
                ? $planSet->lines()
                    ->whereYear('measurement_date', $referenceMonth->year)
                    ->whereMonth('measurement_date', $referenceMonth->month)
                    ->value('realized_monthly_percent')
                : null;

            return TextInput::make("realized.{$planSet->id}")
                ->label($label)
                ->numeric()
                ->suffix('%')
                ->required()
                ->minValue(0.01)
                ->maxValue(100)
                ->default($currentMonthly !== null ? (float) $currentMonthly : null);
        })->all();

        $schema[] = Section::make('Realizado mensal por empreendimento (%)')
            ->description('Informe o avanço físico realizado no mês de referência. O acumulado e a diferença são calculados automaticamente no cronograma.')
            ->schema($fields);

        return $schema;
    }

    /**
     * Developments (plan sets) covered by this measurement, derived from its
     * uploaded assets when available, otherwise from the operation's plan sets.
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

        return $this->record->operation?->planSets()->with('construction')->get() ?? collect();
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
                        ])
                        ->all();

                    $created = $this->workflow()->registerPayments(
                        $this->record,
                        $this->actor(),
                        $rows,
                        expectedRevision: (int) $data['expected_revision'],
                    );

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
     * @return array<int, Component>
     */
    private function registerPaymentSchema(): array
    {
        $labels = $this->planSetLabelMap();

        $rows = collect($labels)
            ->map(fn (string $label, int $planSetId): array => ['plan_set_id' => $planSetId])
            ->values()
            ->all();

        return [
            Hidden::make('expected_revision')->default(fn (): int => (int) $this->record->workflow_revision),
            DatePicker::make('pay_date')->label('Data do pagamento')->required()->default(now()),
            TextInput::make('method')->label('Método')->placeholder('TED, PIX, Boleto...'),
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
                        ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state)),
                    Placeholder::make('reconciliation_divergence')
                        ->label('Conciliação')
                        ->columnSpanFull()
                        ->content(fn (Get $get): HtmlString => $this->paymentDivergenceContent($get('plan_set_id'), $get('amount'))),
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
     * Nesta V1 a divergência avisa e não bloqueia: o texto é neutro porque
     * pagar a menos ou a mais pode ter motivo legítimo.
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
                FileUpload::make('receipt')
                    ->label('Comprovante')->required()->storeFiles(false)
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
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $this->workflow()->finalize(
                        $this->record,
                        $this->actor(),
                        expectedRevision: (int) $data['expected_revision'],
                        expectedStatus: (string) $data['expected_status'],
                    );
                    $this->notify('Medição finalizada.');
                });
            });
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
