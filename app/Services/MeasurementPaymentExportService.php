<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionStatus;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MeasurementPaymentExportService
{
    private const FORMULA_TRIGGER_CHARACTERS = ['=', '+', '-', '@', "\t", "\r"];

    /** @var array<int, Style>|null */
    private ?array $xlsxColumnStyles = null;

    public function __construct(
        private MeasurementOperationalReadModel $readModel,
        private MeasurementRevisionService $revisions,
    ) {}

    /**
     * @param  Builder<Measurement>  $filteredMeasurements
     * @param  array<string, mixed>  $auditContext
     */
    public function download(
        Builder $filteredMeasurements,
        User $actor,
        string $format,
        array $auditContext = [],
    ): BinaryFileResponse {
        abort_unless($actor->can('measurements.export') && $actor->can('measurements.view'), 403);

        $format = in_array($format, ['xlsx', 'csv'], true) ? $format : 'xlsx';
        $directory = 'exports/measurements';
        Storage::disk('local')->makeDirectory($directory);

        $fileName = 'pagamentos-operacionais-'.now()->format('Ymd-His').'.'.$format;
        $relativePath = $directory.'/'.uniqid('payments_', true).'.'.$format;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $writer = null;
        $rowCount = 0;
        $measurementCount = 0;

        try {
            $writer = SimpleExcelWriter::create(
                file: $absolutePath,
                type: $format,
                delimiter: $format === 'csv' ? ';' : null,
                shouldAddBom: $format === 'csv',
            );
            $writer->addHeader($this->headers());

            $authorizedMeasurementIds = Measurement::query()
                ->visibleTo($actor)
                ->select('measurements.id');

            $filteredMeasurementIds = (clone $filteredMeasurements)
                ->whereIn('measurements.id', $authorizedMeasurementIds)
                ->select('measurements.id')
                ->distinct()
                ->reorder();

            Measurement::query()
                ->select('measurements.*')
                ->whereIn('measurements.id', $filteredMeasurementIds)
                ->with([
                    'operation.emission:id,name,bsi_code,if_code,isin_code',
                    'operation.paymentManager:id,name',
                    'assets.planSet.construction:id,development_name',
                    'payments.planSet.construction:id,development_name',
                    'payments.createdByUser:id,name',
                    'payments.receiptUploadedByUser:id,name',
                    'payments.currentReceiptEvidence',
                    'reviews.reviewer:id,name',
                    'pauses:id,measurement_id,stage,paused_at,resumed_at',
                    'reviewingSuccessor:id,previous_revision_id,revision_number,revision_status',
                ])
                ->reorder('measurements.id')
                ->lazyById(200, column: 'measurements.id', alias: 'id')
                ->each(function (Measurement $measurement) use ($writer, $actor, $format, &$rowCount, &$measurementCount): void {
                    $measurementCount++;
                    $payments = $measurement->payments->isEmpty()
                        ? collect([null])
                        : $measurement->payments->sortBy('id')->values();
                    // Uma vez por medição: as linhas de pagamento dela repetem o texto.
                    $revisionPosition = $this->revisionPosition($measurement);

                    foreach ($payments as $payment) {
                        $values = $this->row($measurement, $payment, $actor, $format, $revisionPosition);
                        $writer->addRow($format === 'xlsx'
                            ? Row::fromValuesWithStyles($values, columnStyles: $this->xlsxColumnStyles())
                            : $values);
                        $rowCount++;
                    }
                });

            unset($writer);
            $writer = null;

            activity('measurement_exports')
                ->causedBy($actor)
                ->withProperties([
                    'format' => $format,
                    'scope' => 'current_filtered_view',
                    'measurement_count' => $measurementCount,
                    'row_count' => $rowCount,
                    'filters' => $auditContext,
                ])
                ->log('measurement_payments_exported');

            return response()
                ->download($absolutePath, $fileName, [
                    'Cache-Control' => 'no-store, private',
                    'X-Content-Type-Options' => 'nosniff',
                ])
                ->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            unset($writer);
            Storage::disk('local')->delete($relativePath);

            throw $exception;
        }
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Operação',
            'Código da operação',
            'Emissão',
            'Código da emissão',
            'Medição',
            'Competência',
            'Etapa',
            'Status',
            'Gestor de Pagamento',
            'Delegações operacionais atuais',
            'Plano de medição',
            'Empreendimento',
            'Valor',
            'Data do pagamento',
            'Método',
            'Registrado por',
            'Aprovado por',
            'Status do comprovante',
            'Comprovante enviado por',
            'SLA',
            'Prazo SLA',
            'Criado em',
            'Atualizado em',
            // No fim, para as colunas anteriores manterem a posição: a revisão
            // (R1, R2...) liga a linha à medição original que ela corrige.
            'Medição original',
            'Revisão',
            'Situação da revisão',
            'Posição financeira da revisão',
        ];
    }

    /**
     * @param  array{text: string|null, has_remaining_payable: bool}  $revisionPosition
     * @return list<bool|float|int|string|DateTimeImmutable|null>
     */
    private function row(
        Measurement $measurement,
        ?MeasurementPayment $payment,
        User $actor,
        string $format,
        array $revisionPosition,
    ): array {
        $operation = $measurement->operation;
        $emission = $operation?->emission;
        $sla = $this->readModel->slaEvaluation($measurement);
        $paymentApproval = $measurement->reviews->first(
            fn (MeasurementReview $review): bool => (int) $review->stage === MeasurementWorkflow::STAGE_PAYMENT
                && $review->status === 'approved',
        );
        $delegate = $this->readModel->operationalDelegationLabel($measurement, $actor);
        $source = $payment ?? $measurement;
        $measurementPlanSets = $measurement->assets
            ->pluck('planSet')
            ->filter()
            ->unique('id');
        $planSet = $payment?->planSet;
        $planSetLabel = $planSet?->name ?? $measurementPlanSets->pluck('name')->filter()->implode(' · ');
        $constructionLabel = $planSet?->construction?->development_name
            ?? $measurementPlanSets->pluck('construction.development_name')->filter()->implode(' · ');

        return [
            $this->text($operation?->title),
            $this->text($operation?->code),
            $this->text($emission?->name),
            $this->text($emission?->bsi_code ?? $emission?->if_code ?? $emission?->isin_code),
            (int) $measurement->getKey(),
            $this->date($measurement->reference_month, $format, monthOnly: true),
            $this->text($this->readModel->stageLabel($measurement)),
            $this->text(Measurement::STATUS_OPTIONS[$measurement->status] ?? $measurement->status),
            $this->text($operation?->paymentManager?->name),
            $this->text($delegate === 'Sem delegação ativa' ? null : $delegate),
            $this->text($planSetLabel ?: null),
            $this->text($constructionLabel ?: null),
            $payment ? (float) $payment->amount : null,
            $this->date($payment?->pay_date, $format),
            $this->text($payment?->method),
            $this->text($payment?->createdByUser?->name),
            $this->text($paymentApproval?->reviewer?->name),
            $this->text(match (true) {
                $payment !== null => $payment->hasReceipt() ? 'Anexado' : 'Pendente',
                // "Pago em revisão anterior" só quando nada falta pagar: com saldo
                // a pagar em aberto, a família não pagou tudo o que a revisão aprova.
                $measurement->isRevision() && $this->revisions->familyHasHistoricalPayments($measurement) => $revisionPosition['has_remaining_payable']
                    ? 'Sem pagamento nesta revisão — saldo a pagar em aberto'
                    : 'Sem pagamento nesta revisão — pago em revisão anterior',
                default => 'Pagamento não registrado',
            }),
            $this->text($payment?->receiptUploadedByUser?->name),
            $this->text($this->readModel->slaLabel($measurement)),
            $this->dateTime($sla['deadline_at'], $format),
            $this->dateTime($source->created_at, $format),
            $this->dateTime($source->updated_at, $format),
            $measurement->familyRootId(),
            $this->text($measurement->isRevision() ? $measurement->revisionLabel() : 'R0 · Original'),
            $this->text($measurement->revisionStatus()->label()),
            $this->text($revisionPosition['text']),
        ];
    }

    /**
     * A posição financeira da revisão para a planilha, por empreendimento e
     * nunca compensada entre eles. Só a revisão vigente tem posição que vale:
     * a calculada ou, enquanto a Engenharia a reanalisa, a última conhecida.
     * A substituída remete à seguinte, e a original (R0) não tem posição de
     * revisão. Valores da conta exata, em texto -- nunca float.
     *
     * @return array{text: string|null, has_remaining_payable: bool}
     */
    private function revisionPosition(Measurement $measurement): array
    {
        $withoutPosition = ['text' => null, 'has_remaining_payable' => false];

        if (! $measurement->isRevision()) {
            return $withoutPosition;
        }

        if ($measurement->revisionStatus() === MeasurementRevisionStatus::Superseded) {
            return ['text' => MeasurementRevisionAdjustmentStatus::PositionSuperseded->label(), 'has_remaining_payable' => false];
        }

        if (! $measurement->isEffectiveRevision()) {
            return $withoutPosition;
        }

        $positions = collect($this->revisions->positions($measurement));

        if ($positions->isNotEmpty()) {
            $finalized = $measurement->status === 'finalized';

            return [
                'text' => $positions
                    ->map(fn (MeasurementRevisionPlanSetPosition $position): string => $this->describePosition($position, $finalized))
                    ->implode(' | '),
                'has_remaining_payable' => $positions->contains(
                    fn (MeasurementRevisionPlanSetPosition $position): bool => $position->hasRemainingPayable(),
                ),
            ];
        }

        $lastKnown = collect($this->revisions->lastKnownPositions($measurement));

        if ($lastKnown->isEmpty()) {
            return $withoutPosition;
        }

        $invalidatedAt = $lastKnown->pluck('invalidated_at')->filter()->first();

        return [
            'text' => sprintf(
                'Revisão em reanálise da Engenharia — última posição financeira conhecida%s: %s',
                $invalidatedAt === null ? '' : " (registro invalidado em {$invalidatedAt})",
                $lastKnown->map(fn (array $position): string => $this->describeLastKnownPosition($position))->implode(' | '),
            ),
            'has_remaining_payable' => $lastKnown->contains(
                fn (array $position): bool => self::isPositive($position['remaining_payable_amount']),
            ),
        ];
    }

    /**
     * Aprovado vigente, pago pela família e o que fica em aberto. O valor pago
     * a maior em aberto é o reconhecido anteriormente mais o desta revisão --
     * a reconhecer até a Finalização, reconhecido nela --, e reconhecer não o
     * regulariza.
     */
    private function describePosition(MeasurementRevisionPlanSetPosition $position, bool $finalized): string
    {
        $familyPaid = 'pago pela família '.MeasurementFinancialReconciliationService::formatCurrency($position->familyPaidAmount);

        if (! $position->hasFinancialReference()) {
            return sprintf(
                '%s: sem referência financeira (sem Fundo de Obra); %s%s',
                $position->label,
                $familyPaid,
                $position->isExposureUnmeasurable() ? '; exposição não mensurável' : '',
            );
        }

        $approved = sprintf(
            '%s: aprovado vigente %s; %s',
            $position->label,
            MeasurementFinancialReconciliationService::formatCurrency($position->revisedApprovedAmount),
            $familyPaid,
        );

        if ($position->hasOutstandingOverpayment()) {
            $outstanding = (string) $position->outstandingOverpaymentAmount;
            $previouslyAcknowledged = $position->previouslyAcknowledgedOverpaymentAmount ?? '0.00';
            $thisRevision = bcsub($outstanding, $previouslyAcknowledged, 2);

            return sprintf(
                '%s; pago a maior em aberto %s (reconhecido anteriormente %s; %s %s)',
                $approved,
                MeasurementFinancialReconciliationService::formatCurrency($outstanding),
                MeasurementFinancialReconciliationService::formatCurrency($previouslyAcknowledged),
                $finalized ? 'reconhecido nesta revisão' : 'a reconhecer nesta revisão',
                MeasurementFinancialReconciliationService::formatCurrency(self::isPositive($thisRevision) ? $thisRevision : '0.00'),
            );
        }

        if ($position->hasRemainingPayable()) {
            return $approved.'; saldo a pagar em aberto '.MeasurementFinancialReconciliationService::formatCurrency($position->remainingPayableAmount);
        }

        return $approved.'; sem diferença em aberto';
    }

    /**
     * A última posição conhecida da revisão em reanálise: o aprovado é o do
     * registro invalidado, e a reanálise pode mudá-lo.
     *
     * @param  array<string, mixed>  $position
     */
    private function describeLastKnownPosition(array $position): string
    {
        $familyPaid = 'pago pela família '.MeasurementFinancialReconciliationService::formatCurrency((string) $position['family_paid_amount']);

        if ($position['revised_approved_amount'] === null) {
            return sprintf(
                '%s: sem referência financeira (sem Fundo de Obra); %s%s',
                $position['label'],
                $familyPaid,
                $position['unresolved'] ? '; exposição não mensurável' : '',
            );
        }

        $approved = sprintf(
            '%s: aprovado no registro invalidado %s; %s',
            $position['label'],
            MeasurementFinancialReconciliationService::formatCurrency((string) $position['revised_approved_amount']),
            $familyPaid,
        );

        return match (true) {
            self::isPositive($position['outstanding_overpayment_amount']) => $approved.'; pago a maior em aberto '.MeasurementFinancialReconciliationService::formatCurrency((string) $position['outstanding_overpayment_amount']),
            self::isPositive($position['remaining_payable_amount']) => $approved.'; saldo a pagar em aberto '.MeasurementFinancialReconciliationService::formatCurrency((string) $position['remaining_payable_amount']),
            default => $approved.'; sem diferença em aberto',
        };
    }

    private static function isPositive(mixed $amount): bool
    {
        return is_string($amount) && bccomp($amount, '0', 2) > 0;
    }

    private function text(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $firstCharacter = $value[0] ?? null;
        $firstSignificantCharacter = ltrim($value)[0] ?? null;
        $isFormula = in_array($firstCharacter, self::FORMULA_TRIGGER_CHARACTERS, true)
            || in_array($firstSignificantCharacter, self::FORMULA_TRIGGER_CHARACTERS, true);

        return $isFormula ? "'{$value}" : $value;
    }

    private function date(mixed $value, string $format, bool $monthOnly = false): DateTimeImmutable|string|null
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        if ($format === 'csv') {
            return $value->format($monthOnly ? 'm/Y' : 'd/m/Y');
        }

        return DateTimeImmutable::createFromInterface($value);
    }

    private function dateTime(mixed $value, string $format): DateTimeImmutable|string|null
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return $format === 'csv'
            ? $value->format('d/m/Y H:i')
            : DateTimeImmutable::createFromInterface($value);
    }

    /** @return array<int, Style> */
    private function xlsxColumnStyles(): array
    {
        return $this->xlsxColumnStyles ??= [
            5 => (new Style)->setFormat('mm/yyyy'),
            12 => (new Style)->setFormat('#,##0.00'),
            13 => (new Style)->setFormat('dd/mm/yyyy'),
            20 => (new Style)->setFormat('dd/mm/yyyy hh:mm'),
            21 => (new Style)->setFormat('dd/mm/yyyy hh:mm'),
            22 => (new Style)->setFormat('dd/mm/yyyy hh:mm'),
        ];
    }
}
