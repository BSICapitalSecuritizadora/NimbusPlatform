<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Facades\DB;

class PuCurvePersistenceService
{
    public function __construct(
        private readonly LegacyProjectionService $legacyProjectionService,
    ) {}

    public function handle(Emission $emission, PuCurveGenerationResult $result, bool $syncLegacyProjections = true): PuCurveGenerationResult
    {
        $persistedResult = $result;

        DB::transaction(function () use ($emission, $result, $syncLegacyProjections, &$persistedResult): void {
            $calculationVersion = $result->calculationVersion ?? $this->nextCalculationVersion($emission);
            $persistedResult = $result->withCalculationVersion($calculationVersion);
            $timestamp = now();
            $rows = array_map(function ($row) use ($emission, $timestamp, $calculationVersion): array {
                return [
                    ...$row->toPersistenceArray($emission->id, $calculationVersion),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }, $persistedResult->rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                EmissionPuDailyCurve::query()->insert($chunk);
            }

            if ($syncLegacyProjections && ($emission->puParameter?->legacy_projection_enabled ?? true)) {
                $this->legacyProjectionService->sync($emission, $persistedResult);
            }
        });

        return $persistedResult;
    }

    private function nextCalculationVersion(Emission $emission): string
    {
        $latestVersionNumber = EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->pluck('calculation_version')
            ->filter()
            ->map(function (string $version): int {
                if (preg_match('/^v(?P<number>\d+)$/', $version, $matches) === 1) {
                    return (int) $matches['number'];
                }

                return 0;
            })
            ->max() ?? 0;

        return 'v'.($latestVersionNumber + 1);
    }
}
