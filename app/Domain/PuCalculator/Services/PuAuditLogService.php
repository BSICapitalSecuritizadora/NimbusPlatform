<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteCheckResult;
use App\Domain\PuCalculator\DTOs\PuValidationFieldDifference;
use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\DTOs\PuValidationRowResult;
use App\Models\Emission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class PuAuditLogService
{
    public const LOG_NAME = 'pu-calculation';

    public const ENGINE_VERSION = 'phase1-cdi-v1';

    private const MAX_STORED_DIFFERENCES = 150;

    public function logGenerationCompleted(
        Emission $emission,
        PuCurveGenerationResult $result,
        ?int $requestedByUserId,
        PuCurvePrerequisiteCheckResult $prerequisiteCheck,
        bool $syncLegacyProjections,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'calculation_version' => $result->calculationVersion,
                'rows_count' => count($result->rows),
                'sync_legacy_projections' => $syncLegacyProjections,
                'parameter_snapshot' => $this->parameterSnapshot($emission),
                'prerequisites' => $prerequisiteCheck->toArray(),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('generated')->log('pu_curve_generated');
    }

    public function logGenerationFailed(
        Emission $emission,
        string $errorMessage,
        ?int $requestedByUserId,
        ?PuCurvePrerequisiteCheckResult $prerequisiteCheck = null,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'error_message' => $errorMessage,
                'parameter_snapshot' => $this->parameterSnapshot($emission),
                'prerequisites' => $prerequisiteCheck?->toArray(),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('failed')->log('pu_curve_generation_failed');
    }

    public function logValidation(
        Emission $emission,
        PuValidationReport $report,
        string $spreadsheetPath,
        ?int $requestedByUserId,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'spreadsheet_path' => $spreadsheetPath,
                'spreadsheet_name' => basename($spreadsheetPath),
                'sheet_name' => $report->sheetName,
                'calculation_version' => $report->calculationVersion,
                'mode' => $report->mode->value,
                'status' => $report->status->value,
                'total_rows_compared' => $report->totalRowsCompared,
                'total_divergences' => $report->totalDivergences,
                'total_field_divergences' => $report->totalFieldDivergences,
                'first_divergence_date' => $report->firstDivergenceDate?->toDateString(),
                'largest_pu_difference' => $report->largestPuDifference,
                'largest_total_value_difference' => $report->largestTotalValueDifference,
                'largest_payment_difference' => $report->largestPaymentDifference,
                'largest_differences_by_field' => $this->largestDifferencesByField($report),
                'divergence_count_by_field' => $report->divergenceCountByField,
                'divergence_count_by_cause' => $report->divergenceCountByCause,
                'severity_count_by_level' => $this->severityCountByLevel($report),
                'sample_differences' => $this->sampleDifferences($emission, $report),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('validated')->log('pu_curve_validated');
    }

    public function latestValidationActivity(Emission $emission): ?Activity
    {
        /** @var Activity|null $activity */
        $activity = Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', $emission::class)
            ->where('subject_id', $emission->id)
            ->where('description', 'pu_curve_validated')
            ->latest('id')
            ->first();

        return $activity;
    }

    private function causer(?int $requestedByUserId): ?User
    {
        if ($requestedByUserId === null) {
            return null;
        }

        return User::query()->find($requestedByUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterSnapshot(Emission $emission): array
    {
        $parameter = $emission->puParameter;

        if ($parameter === null) {
            return [];
        }

        return [
            'curve_start_date' => $parameter->curve_start_date?->toDateString(),
            'curve_end_date' => $parameter->curve_end_date?->toDateString(),
            'initial_unit_value' => $parameter->getRawOriginal('initial_unit_value'),
            'spread_rate' => $parameter->getRawOriginal('spread_rate'),
            'indexer' => $parameter->indexer,
            'business_day_basis' => $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
            'index_rate_lag_business_days' => $parameter->index_rate_lag_business_days,
            'legacy_projection_enabled' => $parameter->legacy_projection_enabled,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function largestDifferencesByField(PuValidationReport $report): array
    {
        $payload = [];

        foreach ($report->largestDifferencesByField as $field => $difference) {
            $payload[$field] = $this->differencePayload(null, $difference);
        }

        return $payload;
    }

    /**
     * @return array<string, int>
     */
    private function severityCountByLevel(PuValidationReport $report): array
    {
        $counts = [];

        foreach ($report->rows as $row) {
            foreach ($row->differences as $difference) {
                $level = $difference->severity?->value ?? 'alta';
                $counts[$level] = ($counts[$level] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleDifferences(Emission $emission, PuValidationReport $report): array
    {
        $payload = [];

        foreach ($report->rows as $row) {
            foreach ($row->differences as $difference) {
                $payload[] = $this->differencePayload($row, $difference) + [
                    'operation' => $emission->name,
                ];

                if (count($payload) >= self::MAX_STORED_DIFFERENCES) {
                    return $payload;
                }
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function differencePayload(?PuValidationRowResult $row, PuValidationFieldDifference $difference): array
    {
        return [
            'date' => $row?->date->toDateString(),
            'field' => $difference->field,
            'column' => $difference->label,
            'actual' => $difference->actual,
            'expected' => $difference->expected,
            'absolute_difference' => $difference->absoluteDifference,
            'percentage_difference' => $difference->percentageDifference,
            'mode' => $difference->comparisonMode,
            'severity' => $difference->severity?->value,
            'related_rule' => $difference->relatedRule,
            'possible_cause' => $difference->possibleCause,
            'spreadsheet_cell' => $difference->spreadsheetCell,
            'spreadsheet_formula' => $difference->spreadsheetFormula,
        ];
    }
}
