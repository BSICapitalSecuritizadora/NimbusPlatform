<?php

namespace App\Services;

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

    public function __construct(private MeasurementOperationalReadModel $readModel) {}

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
                    'reviews.reviewer:id,name',
                    'pauses:id,measurement_id,stage,paused_at,resumed_at',
                ])
                ->reorder('measurements.id')
                ->lazyById(200, column: 'measurements.id', alias: 'id')
                ->each(function (Measurement $measurement) use ($writer, $actor, $format, &$rowCount, &$measurementCount): void {
                    $measurementCount++;
                    $payments = $measurement->payments->isEmpty()
                        ? collect([null])
                        : $measurement->payments->sortBy('id')->values();

                    foreach ($payments as $payment) {
                        $values = $this->row($measurement, $payment, $actor, $format);
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
        ];
    }

    /**
     * @return list<bool|float|int|string|DateTimeImmutable|null>
     */
    private function row(
        Measurement $measurement,
        ?MeasurementPayment $payment,
        User $actor,
        string $format,
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
            $this->text($payment === null ? 'Pagamento não registrado' : ($payment->hasReceipt() ? 'Anexado' : 'Pendente')),
            $this->text($payment?->receiptUploadedByUser?->name),
            $this->text($this->readModel->slaLabel($measurement)),
            $this->dateTime($sla['deadline_at'], $format),
            $this->dateTime($source->created_at, $format),
            $this->dateTime($source->updated_at, $format),
        ];
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
