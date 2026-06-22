<?php

namespace App\Jobs;

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeneratePuDailyCurveJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly ?int $requestedByUserId = null,
        public readonly bool $confirmedReprocess = false,
    ) {}

    public function handle(
        GeneratePuDailyCurve $generatePuDailyCurve,
        PuCurvePrerequisiteService $prerequisiteService,
        PuAuditLogService $auditLogService,
        PuCurveVersionService $versionService,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $lock = Cache::lock($this->lockKey(), 1800);

        if (! $lock->get()) {
            Cache::put($this->cacheKey(), [
                'status' => 'failed',
                'error' => 'Ja existe uma geracao de curva PU em andamento para esta emissao.',
            ], 1800);

            return;
        }

        $version = null;

        try {
            $emission = Emission::findOrFail($this->emissionId);

            if ($versionService->hasHomologatedVersion($emission) && ! $this->confirmedReprocess) {
                $message = 'Existe uma curva homologada. Reprocessamento exige confirmacao explicita.';
                $auditLogService->logGenerationFailed($emission, $message, $this->requestedByUserId);

                Cache::put($this->cacheKey(), ['status' => 'failed', 'error' => $message], 1800);

                return;
            }

            $prerequisiteCheck = $prerequisiteService->handle($emission);

            if (! $prerequisiteCheck->passes()) {
                $message = $prerequisiteCheck->blockingSummary();
                $auditLogService->logGenerationFailed($emission, $message, $this->requestedByUserId, $prerequisiteCheck);

                Cache::put($this->cacheKey(), ['status' => 'failed', 'error' => $message], 1800);

                return;
            }

            $version = $versionService->startGeneration(
                $emission,
                $this->requestedByUserId,
                $this->parameterSnapshot($emission),
            );

            $result = $generatePuDailyCurve->handle(
                $emission,
                syncLegacyProjections: true,
                calculationVersion: $version->calculation_version,
            );

            $versionService->markGenerated($version, count($result->rows), $result->calculationVersion);
            $auditLogService->logGenerationCompleted(
                $emission,
                $result,
                $this->requestedByUserId,
                $prerequisiteCheck,
                true,
                $this->confirmedReprocess,
            );

            Cache::put($this->cacheKey(), [
                'status' => 'completed',
                'calculation_version' => $result->calculationVersion ?? $version->calculation_version,
                'rows_count' => count($result->rows),
            ], 1800);
        } catch (\Throwable $exception) {
            if ($version instanceof EmissionPuCurveVersion) {
                $versionService->markError($version, $exception->getMessage());
            }

            if (isset($emission) && $emission instanceof Emission) {
                $auditLogService->logGenerationFailed($emission, $exception->getMessage(), $this->requestedByUserId);
            }

            Log::error('GeneratePuDailyCurveJob failed', [
                'emission_id' => $this->emissionId,
                'error' => $exception->getMessage(),
            ]);

            Cache::put($this->cacheKey(), [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], 1800);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put($this->cacheKey(), [
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ], 1800);
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
            'annual_rate' => $parameter->getRawOriginal('annual_rate'),
            'indexer' => $parameter->indexer,
            'calculation_method' => $parameter->resolvedCalculationMethod()->value,
            'method_version' => $parameter->resolvedCalculationMethod()->engineVersion(),
            'business_day_basis' => $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
            'index_rate_lag_business_days' => $parameter->index_rate_lag_business_days,
            'legacy_projection_enabled' => $parameter->legacy_projection_enabled,
        ];
    }

    private function cacheKey(): string
    {
        return sprintf('pu_curve_generation_%d_status', $this->emissionId);
    }

    private function lockKey(): string
    {
        return sprintf('pu_curve_generation_%d_lock', $this->emissionId);
    }
}
