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
        DB::transaction(function () use ($emission, $result, $syncLegacyProjections): void {
            EmissionPuDailyCurve::query()
                ->where('emission_id', $emission->id)
                ->delete();

            $timestamp = now();
            $rows = array_map(function ($row) use ($emission, $timestamp): array {
                return [
                    ...$row->toPersistenceArray($emission->id),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }, $result->rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                EmissionPuDailyCurve::query()->insert($chunk);
            }

            if ($syncLegacyProjections && ($emission->puParameter?->legacy_projection_enabled ?? true)) {
                $this->legacyProjectionService->sync($emission, $result);
            }
        });

        return $result;
    }
}
