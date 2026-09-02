<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateCurvePersistencePlan;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Carbon\CarbonImmutable;

final class PuCandidateCurvePersistencePlanService
{
    public const ACTION_NOT_READY = 'candidate_persistence_not_ready';

    public const ACTION_READY_TO_PERSIST = 'ready_to_persist';

    public const ACTION_ALREADY_PERSISTED = 'already_persisted';

    public const ACTION_CONFLICT = 'candidate_conflict';

    public function __construct(
        private readonly PuNumericHomologationService $homologation,
    ) {}

    public function plan(Emission $emission, CarbonImmutable $asOf): PuCandidateCurvePersistencePlan
    {
        $asOf = $asOf->startOfDay();
        $result = $this->homologation->evaluate($emission, $asOf);
        $operational = ($emission->fresh() ?? $emission)->latestPuCurveVersion()->first();
        $candidates = EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->candidate()
            ->whereDate('candidate_as_of', $asOf)
            ->orderBy('id')
            ->get();
        $candidate = $result->candidate;
        $fingerprint = $result->plan->inputFingerprint;
        $identical = null;
        $sameInputDifferentOutput = false;

        if ($fingerprint !== null && $candidate !== null) {
            $identical = $candidates->first(
                fn (EmissionPuCurveVersion $version): bool => hash_equals(
                    (string) $version->input_fingerprint,
                    $fingerprint,
                ) && hash_equals((string) $version->curve_checksum, $candidate->checksum),
            );
            $sameInputDifferentOutput = $candidates->contains(
                fn (EmissionPuCurveVersion $version): bool => hash_equals(
                    (string) $version->input_fingerprint,
                    $fingerprint,
                ) && ! hash_equals((string) $version->curve_checksum, $candidate->checksum),
            );
        }

        [$action, $reason] = match (true) {
            $result->action !== PuNumericHomologationService::ACTION_READY_FOR_REVIEW,
            $candidate === null,
            $fingerprint === null => [
                self::ACTION_NOT_READY,
                'A homologação numérica não produziu uma candidate ready_for_review; nada pode ser persistido.',
            ],
            $sameInputDifferentOutput => [
                self::ACTION_CONFLICT,
                'O mesmo input fingerprint já possui checksum diferente; persistência bloqueada para investigação.',
            ],
            $identical instanceof EmissionPuCurveVersion => [
                self::ACTION_ALREADY_PERSISTED,
                'Uma candidate idêntica já está persistida; nenhuma duplicata será criada.',
            ],
            default => [
                self::ACTION_READY_TO_PERSIST,
                'A candidate validada está pronta para persistência append-only e isolada.',
            ],
        };

        return new PuCandidateCurvePersistencePlan(
            action: $action,
            reason: $reason,
            homologation: $result,
            currentOperationalVersionId: $operational?->id,
            currentOperationalCalculationVersion: $operational?->calculation_version,
            candidateCount: $candidates->count(),
            identicalCandidateId: $identical?->id,
            divergentCandidateIds: $candidates
                ->reject(fn (EmissionPuCurveVersion $version): bool => $version->is($identical))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }
}
