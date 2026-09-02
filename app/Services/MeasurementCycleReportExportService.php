<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\DTOs\Measurements\MeasurementCycleReportRow;
use App\Enums\AccessPermission;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MeasurementCycleReportExportService
{
    private const FORMULA_TRIGGER_CHARACTERS = ['=', '+', '-', '@', "\t", "\r"];

    /** @var array<int, Style>|null */
    private ?array $xlsxColumnStyles = null;

    public function __construct(private MeasurementCycleReportingService $reporting) {}

    public function download(
        User $actor,
        MeasurementCycleReportFilters $filters,
        string $format,
    ): BinaryFileResponse {
        abort_unless(
            $actor->can(AccessPermission::MeasurementsView->value)
                && $actor->can(AccessPermission::MeasurementsCycleReportsView->value)
                && $actor->can(AccessPermission::MeasurementsCycleReportsExport->value),
            403,
        );

        $format = in_array($format, ['csv', 'xlsx'], true) ? $format : 'xlsx';
        $directory = 'exports/measurement-cycle-reports';
        Storage::disk('local')->makeDirectory($directory);
        $relativePath = $directory.'/'.uniqid('cycle_', true).'.'.$format;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $fileName = 'relatorio-ciclo-medicoes-'.now()->format('Ymd-His').'.'.$format;
        $writer = null;
        $rowCount = 0;
        $measurementIds = [];
        $completenessCounts = ['complete' => 0, 'partial' => 0, 'insufficient' => 0];

        try {
            $writer = SimpleExcelWriter::create(
                file: $absolutePath,
                type: $format,
                delimiter: $format === 'csv' ? ';' : null,
                shouldAddBom: $format === 'csv',
            );
            $writer->addHeader($this->headers());

            foreach ($this->reporting->stageVisitRows($actor, $filters) as $row) {
                $values = $this->row($row, $format);
                $writer->addRow($format === 'xlsx'
                    ? Row::fromValuesWithStyles($values, columnStyles: $this->xlsxColumnStyles())
                    : $values);
                $rowCount++;
                $measurementIds[$row->measurementId] = true;
                $completenessCounts[$row->completeness->value]++;
            }

            unset($writer);
            $writer = null;

            activity('measurement_cycle_report_exports')
                ->causedBy($actor)
                ->withProperties([
                    'actor_id' => (int) $actor->getKey(),
                    'format' => $format,
                    'scope' => 'current_visible_filtered_stage_visits',
                    'filters' => $filters->sanitizedAuditContext(),
                    'stage_visit_count' => $rowCount,
                    'measurement_count' => count($measurementIds),
                    'completeness_counts' => $completenessCounts,
                ])
                ->log('measurement_cycle_report_exported');

            $response = response()->download($absolutePath, $fileName, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
            $response->setPrivate();
            $response->setMaxAge(0);
            $response->headers->addCacheControlDirective('no-store');

            return $response->deleteFileAfterSend(true);
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
            'Emissão',
            'Medição',
            'Competência',
            'Etapa',
            'Sequência',
            'Entrada',
            'Saída',
            'Motivo da saída',
            'Duração calendário (segundos)',
            'Tempo em pausa (segundos)',
            'Duração líquida (segundos)',
            'Actor',
            'Responsabilidade histórica',
            'Responsável esperado',
            'Delegado',
            'Delegante',
            'Admin override',
            'Cobertura',
        ];
    }

    /** @return list<DateTimeImmutable|float|int|string|null> */
    private function row(MeasurementCycleReportRow $row, string $format): array
    {
        return [
            $this->text($row->operationLabel),
            $this->text($row->emissionLabel),
            $this->text($row->measurementLabel),
            $this->month($row->referenceMonth, $format),
            $row->stage,
            $row->sequence,
            $this->dateTime($row->enteredAt, $format),
            $this->dateTime($row->exitedAt, $format),
            $this->text($row->exitReason->value),
            $row->calendarDuration,
            $row->pausedDuration,
            $row->activeDuration,
            $this->text($row->actorName),
            $this->text($row->responsibility?->label()),
            $this->text($row->expectedResponsibleName),
            $this->text($this->triState($row->delegated)),
            $this->text($row->delegatorName),
            $this->text($this->triState($row->adminOverride)),
            $this->text($row->completeness->value),
        ];
    }

    private function text(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $firstCharacter = $value[0] ?? null;
        $firstSignificantCharacter = ltrim($value)[0] ?? null;

        return in_array($firstCharacter, self::FORMULA_TRIGGER_CHARACTERS, true)
            || in_array($firstSignificantCharacter, self::FORMULA_TRIGGER_CHARACTERS, true)
                ? "'{$value}"
                : $value;
    }

    private function month(?string $value, string $format): DateTimeImmutable|string|null
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof DateTimeImmutable) {
            return null;
        }

        return $format === 'csv' ? $date->format('m/Y') : $date;
    }

    private function dateTime(mixed $value, string $format): DateTimeImmutable|string|null
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return $format === 'csv'
            ? $value->format('d/m/Y H:i:s')
            : DateTimeImmutable::createFromInterface($value);
    }

    private function triState(?bool $value): string
    {
        return match ($value) {
            true => 'Sim',
            false => 'Não',
            null => 'N/D',
        };
    }

    /** @return array<int, Style> */
    private function xlsxColumnStyles(): array
    {
        return $this->xlsxColumnStyles ??= [
            3 => (new Style)->setFormat('mm/yyyy'),
            6 => (new Style)->setFormat('dd/mm/yyyy hh:mm:ss'),
            7 => (new Style)->setFormat('dd/mm/yyyy hh:mm:ss'),
            9 => (new Style)->setFormat('0'),
            10 => (new Style)->setFormat('0'),
            11 => (new Style)->setFormat('0'),
        ];
    }
}
