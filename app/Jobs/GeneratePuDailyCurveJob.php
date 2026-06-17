<?php

namespace App\Jobs;

use App\Actions\Emissions\GeneratePuDailyCurve;
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
    ) {}

    public function handle(GeneratePuDailyCurve $generatePuDailyCurve): void
    {
        try {
            $emission = Emission::findOrFail($this->emissionId);
            $result = $generatePuDailyCurve->handle($emission);

            Cache::put($this->cacheKey(), [
                'status' => 'completed',
                'calculation_version' => $result->calculationVersion ?? 'v1',
                'rows_count' => count($result->rows),
            ], 1800);
        } catch (\Throwable $exception) {
            Log::error('GeneratePuDailyCurveJob failed', [
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
        return sprintf('pu_curve_generation_%d_status', $this->emissionId);
    }
}
