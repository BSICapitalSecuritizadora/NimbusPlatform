<?php

namespace App\Jobs;

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Models\Emission;
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
    ) {}

    public function handle(
        GeneratePuDailyCurve $generatePuDailyCurve,
        PuCurvePrerequisiteService $prerequisiteService,
        PuAuditLogService $auditLogService,
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

        try {
            $emission = Emission::findOrFail($this->emissionId);
            $prerequisiteCheck = $prerequisiteService->handle($emission);

            if (! $prerequisiteCheck->passes()) {
                $message = $prerequisiteCheck->blockingSummary();
                $auditLogService->logGenerationFailed($emission, $message, $this->requestedByUserId, $prerequisiteCheck);

                Cache::put($this->cacheKey(), [
                    'status' => 'failed',
                    'error' => $message,
                ], 1800);

                return;
            }

            $result = $generatePuDailyCurve->handle($emission);
            $auditLogService->logGenerationCompleted($emission, $result, $this->requestedByUserId, $prerequisiteCheck, true);

            Cache::put($this->cacheKey(), [
                'status' => 'completed',
                'calculation_version' => $result->calculationVersion ?? 'v1',
                'rows_count' => count($result->rows),
            ], 1800);
        } catch (\Throwable $exception) {
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

    private function cacheKey(): string
    {
        return sprintf('pu_curve_generation_%d_status', $this->emissionId);
    }

    private function lockKey(): string
    {
        return sprintf('pu_curve_generation_%d_lock', $this->emissionId);
    }
}
