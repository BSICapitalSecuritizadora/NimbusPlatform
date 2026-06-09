<?php

namespace App\Filament\Resources\Measurements\Pages;

use App\Concerns\MoneyFormatter;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\MeasurementPayment;
use App\Services\MeasurementWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Support\RawJs;
use Illuminate\Support\Collection;

class ViewMeasurement extends ViewRecord
{
    protected static string $resource = MeasurementResource::class;

    protected static ?string $title = 'Medição';

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
            $this->returnToStageAction(),
            $this->finalizeAction(),
        ];
    }

    private function workflow(): MeasurementWorkflow
    {
        return app(MeasurementWorkflow::class);
    }

    private function isUnderReview(): bool
    {
        return in_array($this->record->status, ['pending', 'in_review'], true);
    }

    private function stage(): int
    {
        return $this->workflow()->unifiedStage($this->record);
    }

    /**
     * Only the user responsible for the given workflow stage (1–5), or a super admin,
     * may validate it.
     */
    private function canValidateStage(int $stage): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        $responsibleId = $this->record->operation?->stageResponsibleId($stage);

        return $responsibleId !== null && (int) $user->id === (int) $responsibleId;
    }

    private function notify(string $message): void
    {
        Notification::make()->success()->title($message)->send();

        $this->record->refresh();
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprovar Etapa')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $this->isUnderReview() && $this->canValidateStage((int) $this->record->current_stage))
            ->schema(fn (): array => $this->approveSchema())
            ->action(function (array $data): void {
                if ($this->record->current_stage === self::ENGINEERING_STAGE && isset($data['realized']) && is_array($data['realized'])) {
                    $this->workflow()->recordRealizedProgress($this->record, $data['realized']);
                }

                $this->workflow()->approve($this->record, auth()->user(), $data['notes'] ?? null);
                $this->notify('Etapa aprovada.');
            });
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function approveSchema(): array
    {
        $schema = [
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
                ->minValue(0)
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
     * @return Collection<int, \App\Models\MeasurementPlanSet>
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
            ->visible(fn (): bool => in_array($this->stage(), [1, 2, 3, MeasurementWorkflow::STAGE_PAYMENT], true) && $this->canValidateStage($this->stage()))
            ->schema([
                Textarea::make('notes')->label('Motivo da recusa')->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $terminal = $this->stage() <= MeasurementWorkflow::STAGE_ENGINEERING;
                $this->workflow()->reject($this->record, auth()->user(), $data['notes']);
                $this->notify($terminal ? 'Medição recusada e encerrada.' : 'Medição devolvida para a etapa anterior.');
            });
    }

    private function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Pausar')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (): bool => $this->record->status !== 'paused' && in_array($this->stage(), [2, 3, MeasurementWorkflow::STAGE_PAYMENT], true) && $this->canValidateStage($this->stage()))
            ->schema([
                Textarea::make('reason')->label('Motivo da pausa')->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $this->workflow()->pause($this->record, auth()->user(), $data['reason']);
                $this->notify('Medição pausada.');
            });
    }

    private function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Retomar')
            ->icon('heroicon-o-play-circle')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->record->status === 'paused' && $this->canValidateStage($this->stage()))
            ->action(function (): void {
                $this->workflow()->resume($this->record, auth()->user());
                $this->notify('Análise retomada.');
            });
    }

    private function registerPaymentAction(): Action
    {
        return Action::make('registerPayment')
            ->label('Registrar Pagamento')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalWidth('2xl')
            ->visible(fn (): bool => $this->record->status === 'awaiting_payment' && $this->canValidateStage(MeasurementWorkflow::STAGE_PAYMENT))
            ->schema(fn (): array => $this->registerPaymentSchema())
            ->action(function (array $data): void {
                $rows = collect($data['payments'] ?? [])
                    ->map(fn (array $row): array => [
                        'plan_set_id' => $row['plan_set_id'] ?? null,
                        'amount' => $row['amount'] ?? null,
                        'pay_date' => $data['pay_date'],
                        'method' => $data['method'] ?? null,
                        'notes' => $row['notes'] ?? null,
                    ])
                    ->all();

                $created = $this->workflow()->registerPayments($this->record, auth()->user(), $rows);

                if ($created->isEmpty()) {
                    Notification::make()->warning()->title('Informe ao menos um valor de pagamento.')->send();

                    return;
                }

                $this->notify($created->count() > 1
                    ? "{$created->count()} pagamentos registrados. Aguardando comprovantes."
                    : 'Pagamento registrado. Aguardando comprovante.');
            });
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function registerPaymentSchema(): array
    {
        $labels = $this->planSetLabelMap();

        $rows = collect($labels)
            ->map(fn (string $label, int $planSetId): array => ['plan_set_id' => $planSetId])
            ->values()
            ->all();

        return [
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
                    Select::make('plan_set_id')
                        ->label('Empreendimento')
                        ->options($labels)
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('amount')
                        ->label('Valor')
                        ->prefix('R$')
                        ->mask(RawJs::make('$money($input, \',\', \'.\')'))
                        ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state)),
                    Textarea::make('notes')->label('Observações')->rows(2)->columnSpanFull(),
                ]),
        ];
    }

    private function attachReceiptAction(): Action
    {
        return Action::make('attachReceipt')
            ->label('Enviar Comprovante')
            ->icon('heroicon-o-paper-clip')
            ->color('info')
            ->modalWidth('2xl')
            ->visible(fn (): bool => $this->record->status === 'awaiting_receipt' && $this->canValidateStage(MeasurementWorkflow::STAGE_PAYMENT) && $this->pendingReceiptPayments()->isNotEmpty())
            ->schema(fn (): array => $this->attachReceiptSchema())
            ->action(function (array $data): void {
                $attached = 0;

                foreach ($data['receipts'] ?? [] as $row) {
                    if (blank($row['receipt'] ?? null)) {
                        continue;
                    }

                    $payment = $this->record->payments()->whereKey($row['payment_id'] ?? null)->first();

                    if ($payment instanceof MeasurementPayment) {
                        $this->workflow()->attachReceipt($payment, $row['receipt']);
                        $attached++;
                    }
                }

                if ($attached === 0) {
                    Notification::make()->warning()->title('Nenhum comprovante enviado.')->send();

                    return;
                }

                $this->notify($attached > 1 ? "{$attached} comprovantes anexados." : 'Comprovante anexado.');
            });
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function attachReceiptSchema(): array
    {
        $labels = $this->planSetLabelMap();

        $rows = $this->pendingReceiptPayments()
            ->map(fn (MeasurementPayment $payment): array => [
                'payment_id' => $payment->id,
                'plan_set_id' => $payment->plan_set_id,
            ])
            ->all();

        return [
            Repeater::make('receipts')
                ->label('Comprovante por pagamento')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->default($rows)
                ->itemLabel(fn (array $state): ?string => $labels[$state['plan_set_id'] ?? null] ?? null)
                ->schema([
                    Hidden::make('payment_id'),
                    Select::make('plan_set_id')
                        ->label('Empreendimento')
                        ->options($labels)
                        ->disabled()
                        ->dehydrated(false),
                    FileUpload::make('receipt')
                        ->label('Comprovante')
                        ->disk('public')
                        ->directory('measurements/receipts'),
                ]),
        ];
    }

    /**
     * @return Collection<int, MeasurementPayment>
     */
    private function pendingReceiptPayments(): Collection
    {
        return $this->record->payments()
            ->whereNull('receipt_path')
            ->orderBy('id')
            ->get();
    }

    private function finalizeAction(): Action
    {
        return Action::make('finalize')
            ->label('Finalizar')
            ->icon('heroicon-o-flag')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Finalizar medição')
            ->modalDescription('Após finalizar, a medição não poderá mais ser alterada.')
            ->visible(fn (): bool => in_array($this->record->status, ['awaiting_payment', 'awaiting_receipt'], true) && $this->canValidateStage(MeasurementWorkflow::STAGE_FINALIZATION))
            ->action(function (): void {
                $this->workflow()->finalize($this->record, auth()->user());
                $this->notify('Medição finalizada.');
            });
    }

    private function returnToStageAction(): Action
    {
        return Action::make('returnToStage')
            ->label('Devolver para Etapa')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => in_array($this->record->status, ['awaiting_payment', 'awaiting_receipt'], true) && $this->canValidateStage(MeasurementWorkflow::STAGE_FINALIZATION))
            ->schema([
                Select::make('target_stage')
                    ->label('Etapa de destino')
                    ->options([
                        1 => 'Etapa 1 — Engenharia',
                        2 => 'Etapa 2 — Gestão',
                        3 => 'Etapa 3 — Compliance',
                        MeasurementWorkflow::STAGE_PAYMENT => 'Etapa 4 — Pagamentos e Comprovantes',
                    ])
                    ->required(),
                Textarea::make('reason')->label('Motivo (opcional)')->rows(2),
            ])
            ->action(function (array $data): void {
                $this->workflow()->returnToStage($this->record, auth()->user(), (int) $data['target_stage'], $data['reason'] ?? null);
                $this->notify('Medição devolvida para a etapa selecionada.');
            });
    }
}
