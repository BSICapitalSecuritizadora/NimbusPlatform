<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Facades\DB;

class PuCurvePersistenceService
{
    public function __construct(
        private readonly LegacyProjectionService $legacyProjectionService,
        private readonly PuCurveVersionService $curveVersions,
    ) {}

    public function handle(
        Emission $emission,
        PuCurveGenerationResult $result,
        bool $syncLegacyProjections = true,
        ?string $calculationVersion = null,
    ): PuCurveGenerationResult {
        $persistedResult = $result;

        DB::transaction(function () use ($emission, $result, $syncLegacyProjections, $calculationVersion, &$persistedResult): void {
            $requestedCalculationVersion = $calculationVersion ?? $result->calculationVersion;
            $version = $requestedCalculationVersion === null
                ? null
                : EmissionPuCurveVersion::query()
                    ->whereBelongsTo($emission)
                    ->operational()
                    ->where('calculation_version', $requestedCalculationVersion)
                    ->latest('id')
                    ->first();
            $createdVersion = ! $version instanceof EmissionPuCurveVersion;
            $version ??= $this->curveVersions->startGeneration(
                emission: $emission,
                requestedByUserId: null,
                calculationVersion: $requestedCalculationVersion,
            );
            $calculationVersion = $version->calculation_version;
            $persistedResult = $result->withCalculationVersion($calculationVersion);
            $timestamp = now();
            $rows = array_map(function ($row) use ($emission, $version, $timestamp, $calculationVersion): array {
                return [
                    ...$row->toPersistenceArray($emission->id, $calculationVersion),
                    'curve_version_id' => $version->id,
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

            if ($createdVersion) {
                $this->curveVersions->markGenerated($version, count($rows), $calculationVersion);
            }
        });

        return $persistedResult;
    }
}
