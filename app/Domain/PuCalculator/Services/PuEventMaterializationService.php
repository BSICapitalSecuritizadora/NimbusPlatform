<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuNumericPreparationPlan;
use App\Domain\PuCalculator\DTOs\PuNumericPreparationResult;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PuEventMaterializationService
{
    public const ACTION_EVENTS_MATERIALIZED = 'events_materialized';

    public const ACTION_ALREADY_PRESENT = 'events_already_present';

    public const ACTION_EVENT_CONFLICT = 'event_conflict';

    public const ACTION_PREPARATION_STATE_CHANGED = 'preparation_state_changed';

    public function __construct(
        private readonly PuNumericPreparationPlanService $plans,
        private readonly PuNumericPreparationActorService $actors,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function write(
        Emission $emission,
        ?string $actorIdentifier,
        ?CarbonImmutable $asOf = null,
    ): PuNumericPreparationResult {
        $asOf = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $plan = $this->plans->plan($emission, $asOf);

        if (! $plan->financialPreparationReady) {
            return $this->resultFromPlan($plan);
        }

        if ($plan->conflictingEvents !== []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_EVENT_CONFLICT,
                reason: 'Eventos persistidos incompatíveis impedem a materialização create-only.',
                writes: 0,
                plan: $plan,
                details: ['conflicts' => $plan->conflictingEvents],
            );
        }

        if ($plan->missingEvents === []) {
            return new PuNumericPreparationResult(
                action: self::ACTION_ALREADY_PRESENT,
                reason: 'Todos os eventos contratuais requeridos já estão presentes.',
                writes: 0,
                plan: $plan,
                details: ['already_present' => array_column($plan->presentEvents, 'id')],
            );
        }

        if ($plan->hasFinancialEffects()) {
            return new PuNumericPreparationResult(
                action: PuNumericPreparationPlanService::ACTION_EXISTING_FINANCIAL_EFFECTS,
                reason: 'Existem efeitos financeiros; a materialização de eventos foi bloqueada.',
                writes: 0,
                plan: $plan,
            );
        }

        $actorResolution = $this->actors->resolve(
            $actorIdentifier,
            AccessPermission::PuParametersConfigure,
        );

        if (! $actorResolution['actor'] instanceof User) {
            return new PuNumericPreparationResult(
                action: $actorResolution['action'],
                reason: $actorResolution['reason'],
                writes: 0,
                plan: $plan,
            );
        }

        try {
            return DB::transaction(fn (): PuNumericPreparationResult => $this->persistUnderLock(
                emission: $emission,
                actorIdentifier: $actorIdentifier,
                asOf: $asOf,
                initialPlan: $plan,
            ));
        } catch (UniqueConstraintViolationException) {
            // unique(emission_id, event_type, effective_date, sequence): uma
            // criação concorrente pode ocupar a identidade física do evento.
            // Nada foi sobrescrito; o estado real é lido novamente e
            // reclassificado.
            $after = $this->plans->plan($emission, $asOf);

            [$action, $reason] = match (true) {
                $after->conflictingEvents !== [] => [
                    self::ACTION_EVENT_CONFLICT,
                    'Uma criação concorrente ocupou a identidade do evento com semântica incompatível; nada foi sobrescrito.',
                ],
                $after->missingEvents === [] => [
                    self::ACTION_ALREADY_PRESENT,
                    'Uma criação concorrente compatível completou os eventos contratuais requeridos.',
                ],
                default => [
                    self::ACTION_PREPARATION_STATE_CHANGED,
                    'Uma criação concorrente alterou os eventos durante a persistência; nenhuma linha foi sobrescrita.',
                ],
            };

            return new PuNumericPreparationResult(
                action: $action,
                reason: $reason,
                writes: 0,
                plan: $after,
                actorId: $actorResolution['actor']->id,
            );
        }
    }

    private function persistUnderLock(
        Emission $emission,
        ?string $actorIdentifier,
        CarbonImmutable $asOf,
        PuNumericPreparationPlan $initialPlan,
    ): PuNumericPreparationResult {
        $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
        $lockedParameter = EmissionPuParameter::query()
            ->whereKey($initialPlan->parameterId)
            ->whereBelongsTo($lockedEmission)
            ->lockForUpdate()
            ->first();
        $actorResolution = $this->actors->resolve(
            $actorIdentifier,
            AccessPermission::PuParametersConfigure,
            lockForUpdate: true,
        );
        $plan = $this->plans->plan($lockedEmission, $asOf);

        if (! $lockedParameter instanceof EmissionPuParameter
            || $plan->parameterId !== $initialPlan->parameterId
            || $plan->eventRequirements !== $initialPlan->eventRequirements) {
            return new PuNumericPreparationResult(
                action: self::ACTION_PREPARATION_STATE_CHANGED,
                reason: 'A configuração ou o cronograma contratual mudou antes da persistência.',
                writes: 0,
                plan: $plan,
                actorId: $actorResolution['actor']?->id,
            );
        }

        if (! $actorResolution['actor'] instanceof User) {
            return new PuNumericPreparationResult(
                action: $actorResolution['action'],
                reason: $actorResolution['reason'],
                writes: 0,
                plan: $plan,
            );
        }

        if (! $plan->canPrepareEvents()) {
            return $plan->missingEvents === []
                ? new PuNumericPreparationResult(
                    action: self::ACTION_ALREADY_PRESENT,
                    reason: 'Outra execução já materializou todos os eventos contratuais.',
                    writes: 0,
                    plan: $plan,
                    actorId: $actorResolution['actor']->id,
                )
                : $this->resultFromPlan($plan, $actorResolution['actor']->id);
        }

        $insertedEventIds = [];

        foreach ($plan->missingEvents as $event) {
            $created = EmissionPuEvent::query()->create([
                'emission_id' => $lockedEmission->id,
                'event_type' => $event['event_type'],
                'original_date' => $event['original_date'],
                'effective_date' => $event['effective_date'],
                'amortization_type' => $event['amortization_type'],
                'amortization_value' => $event['amortization_value'],
                'sequence' => $event['sequence'],
                'description' => 'Materialização controlada do cronograma contratual de PU.',
            ]);
            $insertedEventIds[] = $created->id;
        }

        $this->auditLog->logNumericEventPreparation(
            emission: $lockedEmission,
            parameter: $lockedParameter,
            actor: $actorResolution['actor'],
            requiredEvents: $plan->eventRequirements,
            insertedEventIds: $insertedEventIds,
            alreadyExistingEventIds: array_values(array_map(
                fn (mixed $id): int => (int) $id,
                array_filter(array_column($plan->presentEvents, 'id')),
            )),
            conflicts: [],
            baselineSources: $plan->parameterProvenance['event_baseline_sources'] ?? [],
        );
        $after = $this->plans->plan($lockedEmission, $asOf);

        return new PuNumericPreparationResult(
            action: self::ACTION_EVENTS_MATERIALIZED,
            reason: 'Eventos contratuais criados de forma create-only após rechecagem transacional.',
            writes: count($insertedEventIds),
            plan: $after,
            actorId: $actorResolution['actor']->id,
            details: [
                'inserted_event_ids' => $insertedEventIds,
                'already_present' => array_column($plan->presentEvents, 'id'),
            ],
        );
    }

    private function resultFromPlan(
        PuNumericPreparationPlan $plan,
        ?int $actorId = null,
    ): PuNumericPreparationResult {
        return new PuNumericPreparationResult(
            action: $plan->action,
            reason: $plan->reason,
            writes: 0,
            plan: $plan,
            actorId: $actorId,
        );
    }
}
