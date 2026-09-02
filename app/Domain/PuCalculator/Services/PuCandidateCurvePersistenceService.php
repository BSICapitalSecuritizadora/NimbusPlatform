<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateCurvePersistencePlan;
use App\Domain\PuCalculator\DTOs\PuCandidateCurvePersistenceResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Enums\AccessPermission;
use App\Exceptions\PuCandidateCurveIntegrityException;
use App\Models\BusinessCalendarYear;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persiste, de forma append-only e isolada, a candidate numérica já validada em
 * 2B.5.15. Não recalcula fórmula, não busca nada externo, não materializa taxa,
 * evento ou parâmetro, e nunca toca a curva operacional: o artefato nasce com
 * `curve_role = candidate`, dono forte das suas linhas, e pendente de review.
 */
final class PuCandidateCurvePersistenceService
{
    public const ACTION_PERSISTED = 'candidate_persisted';

    public const ACTION_STATE_CHANGED = 'candidate_persistence_state_changed';

    public const ACTION_INTEGRITY_FAILED = 'candidate_persistence_integrity_failed';

    public const ACTION_ACTOR_REQUIRED = 'candidate_persistence_actor_required';

    public const ACTION_ACTOR_NOT_FOUND = 'candidate_persistence_actor_not_found';

    public const ACTION_ACTOR_INACTIVE = 'candidate_persistence_actor_inactive';

    public const ACTION_ACTOR_UNAPPROVED = 'candidate_persistence_actor_unapproved';

    public const ACTION_ACTOR_UNAUTHORIZED = 'candidate_persistence_unauthorized';

    public function __construct(
        private readonly PuCandidateCurvePersistencePlanService $plans,
        private readonly PuNumericHomologationPlanService $homologationPlans,
        private readonly PuCurveVersionService $curveVersions,
        private readonly PuPersistedCurveChecksumService $persistedChecksums,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function dryRun(
        Emission $emission,
        CarbonImmutable $asOf,
    ): PuCandidateCurvePersistenceResult {
        $plan = $this->plans->plan($emission, $asOf);

        return $this->outcomeFromPlan($plan);
    }

    public function write(
        Emission $emission,
        CarbonImmutable $asOf,
        ?string $actorIdentifier,
    ): PuCandidateCurvePersistenceResult {
        $asOf = $asOf->startOfDay();
        $initialPlan = $this->plans->plan($emission, $asOf);

        if (! filled($actorIdentifier)) {
            return $this->outcomeFromPlan(
                $initialPlan,
                self::ACTION_ACTOR_REQUIRED,
                'Um actor explícito é obrigatório para persistir candidate.',
            );
        }

        try {
            return DB::transaction(function () use (
                $emission,
                $asOf,
                $actorIdentifier,
                $initialPlan,
            ): PuCandidateCurvePersistenceResult {
                $lockedEmission = Emission::query()
                    ->whereKey($emission->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                [$actor, $actorAction, $actorReason] = $this->actorForWrite($actorIdentifier);

                if (! $actor instanceof User) {
                    return $this->outcomeFromPlan($initialPlan, $actorAction, $actorReason);
                }

                if ($initialPlan->action !== PuCandidateCurvePersistencePlanService::ACTION_READY_TO_PERSIST) {
                    return $this->outcomeFromPlan(
                        $initialPlan,
                        actorId: $actor->id,
                    );
                }

                $this->lockCriticalInputs($initialPlan);
                $lockedInputPlan = $this->homologationPlans->plan($lockedEmission, $asOf);
                $expectedFingerprint = $initialPlan->homologation->plan->inputFingerprint;

                if (! $lockedInputPlan->canEvaluate
                    || $expectedFingerprint === null
                    || $lockedInputPlan->inputFingerprint === null
                    || ! hash_equals($expectedFingerprint, $lockedInputPlan->inputFingerprint)) {
                    return $this->outcomeFromPlan(
                        $initialPlan,
                        self::ACTION_STATE_CHANGED,
                        'Os inputs mudaram entre a homologação em memória e a transação; zero writes.',
                        actorId: $actor->id,
                    );
                }

                $candidate = $initialPlan->homologation->candidate;
                $validation = $initialPlan->homologation->validation;

                if ($candidate === null || $validation === null || ! $validation->passed()) {
                    return $this->outcomeFromPlan(
                        $initialPlan,
                        PuCandidateCurvePersistencePlanService::ACTION_NOT_READY,
                        'A candidate deixou de satisfazer ready_for_review antes da persistência.',
                        actorId: $actor->id,
                    );
                }

                $identical = $this->identicalCandidate(
                    $lockedEmission,
                    $asOf,
                    $expectedFingerprint,
                    $candidate->checksum,
                );

                if ($identical instanceof EmissionPuCurveVersion) {
                    return $this->outcomeFromPlan(
                        $initialPlan,
                        PuCandidateCurvePersistencePlanService::ACTION_ALREADY_PERSISTED,
                        'Uma candidate idêntica foi persistida concorrentemente; nenhuma duplicata foi criada.',
                        candidateVersionId: $identical->id,
                        calculationVersion: $identical->calculation_version,
                        actorId: $actor->id,
                    );
                }

                if ($this->sameInputHasDifferentChecksum(
                    $lockedEmission,
                    $asOf,
                    $expectedFingerprint,
                    $candidate->checksum,
                )) {
                    return $this->outcomeFromPlan(
                        $initialPlan,
                        PuCandidateCurvePersistencePlanService::ACTION_CONFLICT,
                        'O mesmo input fingerprint já possui outro checksum; zero writes.',
                        actorId: $actor->id,
                    );
                }

                $timestamp = now();
                $version = EmissionPuCurveVersion::query()->create([
                    'emission_id' => $lockedEmission->id,
                    'calculation_version' => $this->curveVersions->nextCalculationVersion($lockedEmission),
                    'curve_role' => PuCurveRole::Candidate,
                    'candidate_as_of' => $asOf->toDateString(),
                    'input_fingerprint' => $expectedFingerprint,
                    'curve_checksum' => $candidate->checksum,
                    'internal_validation_status' => PuCurveInternalValidationStatus::Passed,
                    'external_validation_status' => PuCurveExternalValidationStatus::Pending,
                    'review_status' => PuCurveReviewStatus::PendingReview,
                    'batch_id' => (string) Str::uuid(),
                    'status' => PuCurveStatus::Validated,
                    'engine_version' => PuAuditLogService::ENGINE_VERSION,
                    'parameters_snapshot' => $initialPlan->homologation->plan->parameterSnapshot,
                    'rows_count' => $candidate->rowCount,
                    'validation_summary' => [
                        'candidate' => [
                            'from' => $candidate->from,
                            'to' => $candidate->to,
                            'initial_unit_value' => $candidate->initialUnitValue,
                            'last_unit_value' => $candidate->lastUnitValue,
                            'checkpoints' => $candidate->checkpoints,
                        ],
                        'internal_validation' => $validation->toArray(),
                        'external_comparison' => $initialPlan->homologation->comparison?->toArray(),
                    ],
                    'generated_by' => $actor->id,
                    'generated_at' => $timestamp,
                    'validated_at' => $timestamp,
                ]);
                $rows = array_map(
                    fn (PuDailyCurveRowData $row): array => [
                        ...$row->toPersistenceArray($lockedEmission->id, $version->calculation_version),
                        'curve_version_id' => $version->id,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $candidate->rows,
                );

                foreach (array_chunk($rows, 500) as $chunk) {
                    EmissionPuDailyCurve::query()->insert($chunk);
                }

                $persistedRowCount = $version->dailyCurves()->count();

                if ($persistedRowCount !== $candidate->rowCount) {
                    throw new PuCandidateCurveIntegrityException(sprintf(
                        'Candidate row count mismatch: expected %d, persisted %d.',
                        $candidate->rowCount,
                        $persistedRowCount,
                    ));
                }

                $persistedChecksum = $this->persistedChecksums->checksum($version);

                if (! hash_equals($candidate->checksum, $persistedChecksum)) {
                    throw new PuCandidateCurveIntegrityException('Candidate checksum mismatch after persistence.');
                }

                $this->auditLog->logCandidateCurvePersisted(
                    emission: $lockedEmission,
                    version: $version,
                    actor: $actor,
                    validation: $validation,
                    externalComparison: $initialPlan->homologation->comparison,
                );

                return $this->outcomeFromPlan(
                    $initialPlan,
                    self::ACTION_PERSISTED,
                    'Candidate persistida como artefato append-only, não operacional e pendente de review.',
                    candidateVersionId: $version->id,
                    calculationVersion: $version->calculation_version,
                    actorId: $actor->id,
                    writes: $candidate->rowCount + 1,
                );
            });
        } catch (UniqueConstraintViolationException) {
            $currentPlan = $this->plans->plan($emission, $asOf);

            return $this->outcomeFromPlan($currentPlan);
        } catch (PuCandidateCurveIntegrityException $exception) {
            // A transação já foi revertida pelo throw: nem a versão nem as linhas
            // sobrevivem a uma quebra de invariante entre candidate e persistido.
            return $this->outcomeFromPlan(
                $initialPlan,
                self::ACTION_INTEGRITY_FAILED,
                $exception->getMessage().' A transação foi revertida.',
            );
        }
    }

    /** @return array{0:?User,1:string,2:string} */
    private function actorForWrite(string $identifier): array
    {
        $normalizedIdentifier = trim($identifier);
        $query = User::query()->lockForUpdate();
        $actor = ctype_digit($normalizedIdentifier)
            ? $query->whereKey((int) $normalizedIdentifier)->first()
            : $query->where('email', $normalizedIdentifier)->first();

        if (! $actor instanceof User) {
            return [null, self::ACTION_ACTOR_NOT_FOUND, 'O actor explícito não existe.'];
        }

        if (! $actor->isActive()) {
            return [null, self::ACTION_ACTOR_INACTIVE, 'O actor explícito está inativo.'];
        }

        if (! $actor->isApproved()) {
            return [null, self::ACTION_ACTOR_UNAPPROVED, 'O actor explícito ainda não foi aprovado.'];
        }

        if (! $actor->can(AccessPermission::PuCurveGenerate->value)) {
            return [null, self::ACTION_ACTOR_UNAUTHORIZED, sprintf(
                'O actor explícito não possui a permission %s.',
                AccessPermission::PuCurveGenerate->value,
            )];
        }

        return [$actor, '', ''];
    }

    private function lockCriticalInputs(PuCandidateCurvePersistencePlan $plan): void
    {
        $homologationPlan = $plan->homologation->plan;

        if ($homologationPlan->parameterId !== null) {
            EmissionPuParameter::query()
                ->whereKey($homologationPlan->parameterId)
                ->lockForUpdate()
                ->get(['id']);
        }

        $rateIds = collect($homologationPlan->rates)->pluck('id')->filter()->all();
        if ($rateIds !== []) {
            IndexRate::query()->whereKey($rateIds)->lockForUpdate()->get(['id']);
        }

        $eventIds = collect($homologationPlan->events)->pluck('id')->filter()->all();
        if ($eventIds !== []) {
            EmissionPuEvent::query()->whereKey($eventIds)->lockForUpdate()->get(['id']);
        }

        $calendar = $homologationPlan->inputPayload['calendar'] ?? [];
        $calendarCode = is_string($calendar['calendar_code'] ?? null)
            ? $calendar['calendar_code']
            : null;
        $calendarYears = collect($calendar['years'] ?? [])->pluck('year')->filter()->all();

        if ($calendarCode !== null && $calendarYears !== []) {
            BusinessCalendarYear::query()
                ->where('calendar_code', $calendarCode)
                ->whereIn('year', $calendarYears)
                ->lockForUpdate()
                ->get(['id']);
        }
    }

    private function identicalCandidate(
        Emission $emission,
        CarbonImmutable $asOf,
        string $inputFingerprint,
        string $curveChecksum,
    ): ?EmissionPuCurveVersion {
        return EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->candidate()
            ->whereDate('candidate_as_of', $asOf)
            ->where('input_fingerprint', $inputFingerprint)
            ->where('curve_checksum', $curveChecksum)
            ->lockForUpdate()
            ->first();
    }

    private function sameInputHasDifferentChecksum(
        Emission $emission,
        CarbonImmutable $asOf,
        string $inputFingerprint,
        string $curveChecksum,
    ): bool {
        return EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->candidate()
            ->whereDate('candidate_as_of', $asOf)
            ->where('input_fingerprint', $inputFingerprint)
            ->where('curve_checksum', '!=', $curveChecksum)
            ->lockForUpdate()
            ->exists();
    }

    private function outcomeFromPlan(
        PuCandidateCurvePersistencePlan $plan,
        ?string $action = null,
        ?string $reason = null,
        ?int $candidateVersionId = null,
        ?string $calculationVersion = null,
        ?int $actorId = null,
        int $writes = 0,
    ): PuCandidateCurvePersistenceResult {
        $candidateVersionId ??= $plan->identicalCandidateId;

        if ($calculationVersion === null && $candidateVersionId !== null) {
            $calculationVersion = EmissionPuCurveVersion::query()
                ->whereKey($candidateVersionId)
                ->value('calculation_version');
        }

        return new PuCandidateCurvePersistenceResult(
            action: $action ?? $plan->action,
            reason: $reason ?? $plan->reason,
            plan: $plan,
            candidateVersionId: $candidateVersionId,
            calculationVersion: $calculationVersion,
            actorId: $actorId,
            writes: $writes,
        );
    }
}
