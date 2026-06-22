<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Support\PuVersionNumber;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Str;

class PuCurveVersionService
{
    /**
     * Cria o registro de versao no estado "processing" antes da geracao começar.
     *
     * @param  array<string, mixed>  $parametersSnapshot
     */
    public function startGeneration(
        Emission $emission,
        ?int $requestedByUserId,
        array $parametersSnapshot = [],
    ): EmissionPuCurveVersion {
        return EmissionPuCurveVersion::query()->create([
            'emission_id' => $emission->id,
            'calculation_version' => $this->nextCalculationVersion($emission),
            'batch_id' => (string) Str::uuid(),
            'status' => PuCurveStatus::Processing,
            'engine_version' => PuAuditLogService::ENGINE_VERSION,
            'parameters_snapshot' => $parametersSnapshot !== [] ? $parametersSnapshot : null,
            'generated_by' => $requestedByUserId,
        ]);
    }

    public function markGenerated(
        EmissionPuCurveVersion $version,
        int $rowsCount,
        ?string $calculationVersion = null,
    ): EmissionPuCurveVersion {
        $version->forceFill([
            'status' => PuCurveStatus::Generated,
            'rows_count' => $rowsCount,
            'calculation_version' => $calculationVersion ?? $version->calculation_version,
            'generated_at' => now(),
            'error_message' => null,
        ])->save();

        $this->markPreviousVersionsObsolete($version);

        return $version;
    }

    public function markError(EmissionPuCurveVersion $version, string $message): EmissionPuCurveVersion
    {
        $version->forceFill([
            'status' => PuCurveStatus::Error,
            'error_message' => $message,
        ])->save();

        return $version;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markValidated(
        EmissionPuCurveVersion $version,
        bool $approved,
        array $summary,
        ?int $validatedByUserId,
    ): EmissionPuCurveVersion {
        $attributes = [
            'validation_summary' => $summary,
            'validated_at' => now(),
            'validated_by' => $validatedByUserId,
        ];

        if ($version->status !== PuCurveStatus::Homologated) {
            $attributes['status'] = $approved ? PuCurveStatus::Validated : PuCurveStatus::Divergent;
        }

        $version->forceFill($attributes)->save();

        return $version;
    }

    public function markHomologated(EmissionPuCurveVersion $version, ?int $homologatedByUserId): EmissionPuCurveVersion
    {
        $version->forceFill([
            'status' => PuCurveStatus::Homologated,
            'homologated_at' => now(),
            'homologated_by' => $homologatedByUserId,
        ])->save();

        return $version;
    }

    public function markInvalidated(EmissionPuCurveVersion $version, ?int $invalidatedByUserId): EmissionPuCurveVersion
    {
        $version->forceFill([
            'status' => PuCurveStatus::Obsolete,
            'obsolete_reason' => 'invalidated',
            'invalidated_at' => now(),
            'invalidated_by' => $invalidatedByUserId,
        ])->save();

        return $version;
    }

    public function findByCalculationVersion(Emission $emission, ?string $calculationVersion): ?EmissionPuCurveVersion
    {
        if ($calculationVersion === null) {
            return $emission->currentPuCurveVersion();
        }

        return EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->where('calculation_version', $calculationVersion)
            ->orderByDesc('id')
            ->first();
    }

    public function hasHomologatedVersion(Emission $emission): bool
    {
        return EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->homologated()
            ->exists();
    }

    /**
     * Proxima versao "vN" considerando linhas de curva e registros de versao ja existentes.
     */
    public function nextCalculationVersion(Emission $emission): string
    {
        $fromCurves = EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->pluck('calculation_version');

        $fromVersions = EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->pluck('calculation_version');

        return PuVersionNumber::formatNext($fromCurves->merge($fromVersions)->all());
    }

    private function markPreviousVersionsObsolete(EmissionPuCurveVersion $version): void
    {
        EmissionPuCurveVersion::query()
            ->where('emission_id', $version->emission_id)
            ->where('id', '!=', $version->id)
            ->whereNotIn('status', [PuCurveStatus::Homologated->value, PuCurveStatus::Obsolete->value])
            ->update([
                'status' => PuCurveStatus::Obsolete->value,
                'obsolete_reason' => 'superseded',
            ]);
    }
}
