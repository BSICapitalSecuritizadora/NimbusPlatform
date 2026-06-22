<?php

namespace App\Jobs;

use App\Actions\Emissions\ValidatePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ValidatePuCurveJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly string $spreadsheetPath,
        public readonly ?string $calculationVersion = null,
        public readonly string $mode = 'raw-scale',
        public readonly ?string $rangeStart = null,
        public readonly ?string $rangeEnd = null,
        public readonly ?int $requestedByUserId = null,
    ) {}

    public function handle(
        ValidatePuDailyCurve $validatePuDailyCurve,
        PuCurveVersionService $versionService,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $emission = Emission::findOrFail($this->emissionId);

            $report = $validatePuDailyCurve->handle(
                $emission,
                $this->spreadsheetPath,
                $this->calculationVersion,
                PuValidationMode::from($this->mode),
                $this->rangeStart !== null ? CarbonImmutable::parse($this->rangeStart) : null,
                $this->rangeEnd !== null ? CarbonImmutable::parse($this->rangeEnd) : null,
                $this->requestedByUserId,
            );

            $approved = $report->status === PuValidationStatus::Approved;
            $version = $versionService->findByCalculationVersion($emission, $report->calculationVersion ?? $this->calculationVersion);

            if ($version !== null) {
                $versionService->markValidated($version, $approved, [
                    'mode' => $report->mode->value,
                    'status' => $report->status->value,
                    'total_rows_compared' => $report->totalRowsCompared,
                    'total_divergences' => $report->totalDivergences,
                    'total_field_divergences' => $report->totalFieldDivergences,
                    'first_divergence_date' => $report->firstDivergenceDate?->toDateString(),
                    'largest_pu_difference' => $report->largestPuDifference,
                    'largest_total_value_difference' => $report->largestTotalValueDifference,
                    'largest_payment_difference' => $report->largestPaymentDifference,
                ], $this->requestedByUserId);
            }

            Cache::put($this->cacheKey(), [
                'status' => 'completed',
                'validation_status' => $report->status->value,
                'calculation_version' => $report->calculationVersion ?? $this->calculationVersion,
                'total_rows_compared' => $report->totalRowsCompared,
                'total_divergences' => $report->totalDivergences,
                'total_field_divergences' => $report->totalFieldDivergences,
            ], 1800);
        } catch (\Throwable $exception) {
            Log::error('ValidatePuCurveJob failed', [
                'emission_id' => $this->emissionId,
                'error' => $exception->getMessage(),
            ]);

            Cache::put($this->cacheKey(), [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], 1800);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put($this->cacheKey(), [
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ], 1800);
    }

    private function cacheKey(): string
    {
        return sprintf('pu_curve_validation_%d_status', $this->emissionId);
    }
}
