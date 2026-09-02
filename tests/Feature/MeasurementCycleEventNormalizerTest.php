<?php

use App\DTOs\Measurements\MeasurementCycleEvent;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementCycleEventNormalizer;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{measurement: Measurement, actor: User, operation: Operation} */
function p3bNormalizerScenario(): array
{
    $actor = User::factory()->create();
    $operation = Operation::factory()->create(['responsible_user_id' => $actor->getKey()]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);

    return compact('measurement', 'actor', 'operation');
}

/**
 * @param  array<string, mixed>  $properties
 * @param  array<string, mixed>  $attributes
 */
function p3bNormalizerWorkflowActivity(
    Measurement $measurement,
    string $description,
    string $occurredAt,
    array $properties = [],
    ?User $causer = null,
    array $attributes = [],
): Activity {
    $common = [
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'delegated' => false,
        'delegation_id' => null,
        'delegator_user_id' => null,
        'delegation_scope' => null,
        'admin_override' => false,
        'actual_actor_user_id' => $causer?->getKey(),
        'workflow_revision' => 1,
    ];

    return Activity::query()->create(array_merge([
        'log_name' => 'measurement_workflow',
        'description' => $description,
        'subject_type' => $measurement->getMorphClass(),
        'subject_id' => $measurement->getKey(),
        'causer_type' => $causer?->getMorphClass(),
        'causer_id' => $causer?->getKey(),
        'event' => 'field_is_not_the_workflow_event_name',
        'properties' => array_merge($common, $properties),
        'created_at' => CarbonImmutable::parse($occurredAt),
        'updated_at' => CarbonImmutable::parse($occurredAt),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $attributes
 */
function p3bNormalizerModelActivity(
    Measurement $measurement,
    string $occurredAt,
    array $old,
    array $attributes,
    ?User $causer = null,
): Activity {
    return Activity::query()->create([
        'log_name' => 'measurements',
        'description' => 'updated',
        'subject_type' => $measurement->getMorphClass(),
        'subject_id' => $measurement->getKey(),
        'causer_type' => $causer?->getMorphClass(),
        'causer_id' => $causer?->getKey(),
        'event' => 'updated',
        'properties' => compact('old', 'attributes'),
        'created_at' => CarbonImmutable::parse($occurredAt),
        'updated_at' => CarbonImmutable::parse($occurredAt),
    ]);
}

it('normalizes only the supported workflow whitelist using description', function () {
    $scenario = p3bNormalizerScenario();
    $definitions = [
        ['measurement_submitted', MeasurementCycleEventType::Submitted, ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id']],
        ['measurement_stage_approved', MeasurementCycleEventType::StageApproved, ['stage' => 1, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_stage_rejected', MeasurementCycleEventType::StageRejected, ['stage' => 2, 'target_stage' => 1, 'from_status' => 'in_review', 'to_status' => 'in_review', 'notes' => 'Retorno', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_finalization_returned', MeasurementCycleEventType::FinalizationReturned, ['stage' => 5, 'target_stage' => 2, 'from_status' => 'approved', 'to_status' => 'in_review', 'notes' => 'Retorno', 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_stage_paused', MeasurementCycleEventType::StagePaused, ['stage' => 1, 'from_status' => 'in_review', 'to_status' => 'paused', 'notes' => 'Pausa', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_stage_resumed', MeasurementCycleEventType::StageResumed, ['stage' => 1, 'from_status' => 'paused', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_payment_registered', MeasurementCycleEventType::PaymentRegistered, ['stage' => 4, 'from_status' => 'awaiting_payment', 'to_status' => 'awaiting_payment', 'payment_ids' => [10, 11], 'amount' => '1500.25', 'responsibility' => 'payment_manager_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_receipt_attached', MeasurementCycleEventType::ReceiptAttached, ['stage' => 5, 'payment_id' => 10, 'from_status' => 'awaiting_receipt', 'to_status' => 'approved', 'responsibility' => 'payment_receipt_uploader_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_receipt_deleted', MeasurementCycleEventType::ReceiptDeleted, ['stage' => 5, 'payment_id' => 10, 'from_status' => 'approved', 'to_status' => 'awaiting_receipt', 'responsibility' => 'payment_receipt_uploader_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_finalized', MeasurementCycleEventType::Finalized, ['stage' => 5, 'from_status' => 'approved', 'to_status' => 'finalized', 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()]],
        ['measurement_engineering_snapshot_created', MeasurementCycleEventType::EngineeringSnapshotCreated, []],
        ['measurement_engineering_snapshot_invalidated', MeasurementCycleEventType::EngineeringSnapshotInvalidated, ['reason' => 'Reentrada']],
    ];

    foreach ($definitions as $index => [$source, $type, $properties]) {
        p3bNormalizerWorkflowActivity(
            $scenario['measurement'],
            $source,
            '2026-08-28 10:'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).':00',
            $properties,
            $scenario['actor'],
        );
    }

    p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_future_unknown_event',
        '2026-08-28 11:00:00',
        [],
        $scenario['actor'],
    );

    $events = app(MeasurementCycleEventNormalizer::class)->normalizeMany(
        Activity::query()
            ->where('subject_id', $scenario['measurement']->getKey())
            ->where('log_name', 'measurement_workflow')
            ->get(),
        $scenario['measurement'],
    );
    $approval = $events->first(
        fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::StageApproved,
    );
    $payment = $events->first(
        fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::PaymentRegistered,
    );

    expect($events->pluck('eventType')->all())->toBe(array_column($definitions, 1))
        ->and($events->pluck('sourceEvent'))->not->toContain('measurement_future_unknown_event')
        ->and($approval?->stageBefore)->toBe(1)
        ->and($approval?->stageAfter)->toBe(2)
        ->and($payment?->paymentIds)->toBe([10, 11])
        ->and($payment?->paymentAmount)->toBe('1500.25');
});

it('uses the actor hierarchy and reports an actual actor and causer mismatch', function () {
    $scenario = p3bNormalizerScenario();
    $other = User::factory()->create();
    $base = [
        'stage' => 1,
        'from_status' => 'in_review',
        'to_status' => 'in_review',
        'responsibility' => 'responsible_user_id',
        'expected_responsible_user_id' => $scenario['actor']->getKey(),
    ];

    $explicit = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-28 10:00:00',
        $base,
        $scenario['actor'],
    );
    $causerOnly = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-28 10:01:00',
        array_merge($base, ['actual_actor_user_id' => null]),
        $scenario['actor'],
    );
    $mismatch = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-28 10:02:00',
        array_merge($base, ['actual_actor_user_id' => $scenario['actor']->getKey()]),
        $other,
    );
    $unknown = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-28 10:03:00',
        array_merge($base, ['actual_actor_user_id' => null]),
    );
    $scenario['measurement']->reviews()->create([
        'stage' => 1,
        'reviewer_user_id' => $other->getKey(),
        'status' => 'approved',
        'reviewed_at' => CarbonImmutable::parse('2026-08-28 10:04:00'),
    ]);
    $scenario['measurement']->load('reviews');
    $durableFallback = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-28 10:04:00',
        array_merge($base, ['actual_actor_user_id' => null]),
    );
    $normalizer = app(MeasurementCycleEventNormalizer::class);
    $correlatedFallback = $normalizer->normalizeMany(
        collect([$durableFallback]),
        $scenario['measurement'],
    )->first();

    expect($normalizer->normalize($explicit, $scenario['measurement'])?->actorId)->toBe($scenario['actor']->getKey())
        ->and($normalizer->normalize($causerOnly, $scenario['measurement'])?->actorId)->toBe($scenario['actor']->getKey())
        ->and($normalizer->normalize($mismatch, $scenario['measurement'])?->actorId)->toBe($scenario['actor']->getKey())
        ->and($normalizer->normalize($mismatch, $scenario['measurement'])?->missingReasons)->toContain('actual_actor_causer_mismatch')
        ->and($normalizer->normalize($unknown, null)?->actorId)->toBeNull()
        ->and($normalizer->normalize($unknown, null)?->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($correlatedFallback?->actorId)->toBe($other->getKey())
        ->and($correlatedFallback?->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($correlatedFallback?->missingReasons)->toContain('actor_from_durable_fallback')
        ->and($correlatedFallback?->missingReasons)->not->toContain('actor_unknown');
});

it('keeps delegation and admin override tri-state and revision as metadata only', function () {
    $scenario = p3bNormalizerScenario();
    $delegated = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_submitted',
        '2026-08-28 10:00:00',
        [
            'stage' => 1,
            'from_status' => 'pending',
            'to_status' => 'in_review',
            'responsibility' => 'responsible_user_id',
            'delegated' => true,
            'delegation_id' => 90,
            'delegator_user_id' => 91,
            'delegation_scope' => [
                'type' => 'operation',
                'operation_id' => $scenario['operation']->getKey(),
                'stage' => null,
                'responsibility' => null,
                'internal_key' => 'must-not-leak',
            ],
            'admin_override' => false,
            'workflow_revision' => 42,
        ],
        $scenario['actor'],
    );
    $normal = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_submitted',
        '2026-08-28 10:01:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        $scenario['actor'],
    );
    $legacy = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_submitted',
        '2026-08-28 10:02:00',
        [
            'stage' => 1,
            'from_status' => 'pending',
            'to_status' => 'in_review',
            'responsibility' => 'responsible_user_id',
            'delegated' => null,
            'admin_override' => null,
            'workflow_revision' => null,
        ],
        $scenario['actor'],
    );
    $legacy->forceFill([
        'properties' => $legacy->properties->except([
            'delegated',
            'delegation_id',
            'delegator_user_id',
            'delegation_scope',
            'admin_override',
            'workflow_revision',
        ])->all(),
    ])->save();
    $override = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_submitted',
        '2026-08-28 10:03:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id', 'admin_override' => true],
        $scenario['actor'],
    );
    $normalizer = app(MeasurementCycleEventNormalizer::class);
    $delegatedEvent = $normalizer->normalize($delegated, $scenario['measurement']);
    $normalEvent = $normalizer->normalize($normal, $scenario['measurement']);
    $legacyEvent = $normalizer->normalize($legacy, $scenario['measurement']);
    $overrideEvent = $normalizer->normalize($override, $scenario['measurement']);

    expect($delegatedEvent?->delegated)->toBeTrue()
        ->and($delegatedEvent?->delegationId)->toBe(90)
        ->and($delegatedEvent?->delegatorId)->toBe(91)
        ->and($delegatedEvent?->delegationScope)->toBe([
            'type' => 'operation',
            'operation_id' => $scenario['operation']->getKey(),
            'stage' => null,
            'responsibility' => null,
        ])
        ->and($delegatedEvent?->adminOverride)->toBeFalse()
        ->and($delegatedEvent?->workflowRevision)->toBe(42)
        ->and($normalEvent?->delegated)->toBeFalse()
        ->and($normalEvent?->adminOverride)->toBeFalse()
        ->and($legacyEvent?->delegated)->toBeNull()
        ->and($legacyEvent?->adminOverride)->toBeNull()
        ->and($legacyEvent?->workflowRevision)->toBeNull()
        ->and($overrideEvent?->adminOverride)->toBeTrue();
});

it('normalizes only unambiguous model activity as a partial fallback and prefers workflow activity', function () {
    $scenario = p3bNormalizerScenario();
    $at = CarbonImmutable::parse('2026-08-20 10:00:00');
    $fallback = Activity::query()->create([
        'log_name' => 'default',
        'description' => 'updated',
        'subject_type' => $scenario['measurement']->getMorphClass(),
        'subject_id' => $scenario['measurement']->getKey(),
        'causer_type' => $scenario['actor']->getMorphClass(),
        'causer_id' => $scenario['actor']->getKey(),
        'event' => 'updated',
        'properties' => [
            'old' => ['current_stage' => 2, 'status' => 'in_review'],
            'attributes' => ['current_stage' => 3, 'status' => 'in_review'],
        ],
        'created_at' => $at,
        'updated_at' => $at,
    ]);
    p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        $at->addSeconds(2)->toDateTimeString(),
        [
            'stage' => 2,
            'from_status' => 'in_review',
            'to_status' => 'in_review',
            'responsibility' => 'stage2_reviewer_user_id',
            'expected_responsible_user_id' => $scenario['actor']->getKey(),
        ],
        $scenario['actor'],
    );
    $normalizer = app(MeasurementCycleEventNormalizer::class);
    $fallbackEvent = $normalizer->normalize($fallback, $scenario['measurement']);
    $events = $normalizer->normalizeMany(
        Activity::query()->where('subject_id', $scenario['measurement']->getKey())->get(),
        $scenario['measurement'],
    );
    $approvalEvents = $events->filter(
        fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::StageApproved,
    );

    expect($fallbackEvent?->sourceType)->toBe(MeasurementHistorySourceType::ModelActivity)
        ->and($fallbackEvent?->eventType)->toBe(MeasurementCycleEventType::StageApproved)
        ->and($fallbackEvent?->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($approvalEvents)->toHaveCount(1)
        ->and($approvalEvents->first()?->sourceType)
        ->toBe(MeasurementHistorySourceType::WorkflowActivity);
});

it('does not expose raw activity metadata or invent receipt replacement and payment approval', function () {
    $scenario = p3bNormalizerScenario();
    $snapshot = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_engineering_snapshot_created',
        '2026-08-28 10:00:00',
        [
            'engineering_snapshot' => ['private' => 'raw-secret'],
            'snapshot_sha256' => 'secret-sha',
            'storage_path' => 'private/path.pdf',
        ],
        $scenario['actor'],
    );
    $firstAttach = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_receipt_attached',
        '2026-08-28 10:01:00',
        ['stage' => 5, 'payment_id' => 1, 'from_status' => 'awaiting_receipt', 'to_status' => 'approved', 'responsibility' => 'payment_receipt_uploader_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        $scenario['actor'],
    );
    $secondAttach = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_receipt_attached',
        '2026-08-28 10:02:00',
        ['stage' => 5, 'payment_id' => 1, 'from_status' => 'approved', 'to_status' => 'approved', 'responsibility' => 'payment_receipt_uploader_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        $scenario['actor'],
    );
    $normalizer = app(MeasurementCycleEventNormalizer::class);
    $serialized = json_encode($normalizer->normalize($snapshot, $scenario['measurement'])?->toArray());

    expect($serialized)->not->toContain('raw-secret', 'secret-sha', 'private/path.pdf', 'engineering_snapshot', 'snapshot_sha256', 'storage_path')
        ->and($normalizer->normalize($firstAttach, $scenario['measurement'])?->eventType)->toBe(MeasurementCycleEventType::ReceiptAttached)
        ->and($normalizer->normalize($secondAttach, $scenario['measurement'])?->eventType)->toBe(MeasurementCycleEventType::ReceiptAttached)
        ->and(array_column(MeasurementCycleEventType::cases(), 'value'))->not->toContain('payment_approved', 'receipt_replaced');
});

it('marks every known workflow event with a contradictory transition shape as insufficient', function () {
    $scenario = p3bNormalizerScenario();
    $actorId = $scenario['actor']->getKey();
    $invalidTransitions = [
        ['measurement_submitted', ['stage' => 1, 'target_stage' => 2, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id']],
        ['measurement_stage_approved', ['stage' => 2, 'from_status' => 'awaiting_payment', 'to_status' => 'in_review', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_approved', ['stage' => 3, 'from_status' => 'in_review', 'to_status' => 'approved', 'responsibility' => 'stage3_reviewer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_rejected', ['stage' => 1, 'target_stage' => 2, 'from_status' => 'in_review', 'to_status' => 'rejected', 'notes' => 'Inválida', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_rejected', ['stage' => 4, 'target_stage' => 1, 'from_status' => 'awaiting_payment', 'to_status' => 'in_review', 'notes' => 'Inválida', 'responsibility' => 'payment_manager_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_finalization_returned', ['stage' => 5, 'target_stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'notes' => 'Inválida', 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_payment_registered', ['stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'payment_ids' => [10], 'responsibility' => 'payment_manager_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_receipt_attached', ['stage' => 2, 'payment_id' => 10, 'from_status' => 'awaiting_receipt', 'to_status' => 'approved', 'responsibility' => 'payment_receipt_uploader_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_paused', ['stage' => 2, 'from_status' => 'paused', 'to_status' => 'paused', 'notes' => 'Inválida', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_resumed', ['stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_finalized', ['stage' => 5, 'from_status' => 'awaiting_receipt', 'to_status' => 'finalized', 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $actorId]],
    ];
    $normalizer = app(MeasurementCycleEventNormalizer::class);

    foreach ($invalidTransitions as $index => [$description, $properties]) {
        $activity = p3bNormalizerWorkflowActivity(
            $scenario['measurement'],
            $description,
            CarbonImmutable::parse('2026-08-29 09:00:00')->addMinutes($index)->toDateTimeString(),
            $properties,
            $scenario['actor'],
        );
        $event = $normalizer->normalize($activity, $scenario['measurement']);

        expect($event?->completeness)->toBe(MeasurementHistoryCompleteness::Insufficient)
            ->and($event?->missingReasons)->toContain('invalid_transition_shape');
    }
});

it('uses an explicit whitelist for legacy transitions and rejects arbitrary regression and incoherent finalization', function () {
    $scenario = p3bNormalizerScenario();
    $arbitraryRegression = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 10:00:00',
        ['current_stage' => 4, 'status' => 'awaiting_payment'],
        ['current_stage' => 1, 'status' => 'in_review'],
        $scenario['actor'],
    );
    $incoherentFinalization = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 10:01:00',
        ['current_stage' => 4, 'status' => 'approved'],
        ['current_stage' => 4, 'status' => 'finalized'],
        $scenario['actor'],
    );
    $validReturn = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 10:02:00',
        ['current_stage' => 5, 'status' => 'approved'],
        ['current_stage' => 4, 'status' => 'awaiting_payment'],
        $scenario['actor'],
    );
    $normalizer = app(MeasurementCycleEventNormalizer::class);

    expect($normalizer->normalize($arbitraryRegression, $scenario['measurement']))->toBeNull()
        ->and($normalizer->normalize($incoherentFinalization, $scenario['measurement']))->toBeNull()
        ->and($normalizer->normalize($validReturn, $scenario['measurement'])?->eventType)
        ->toBe(MeasurementCycleEventType::FinalizationReturned);
});

it('deduplicates explicit and model activities one-to-one using the nearest unmatched candidate', function () {
    $scenario = p3bNormalizerScenario();
    $normalizer = app(MeasurementCycleEventNormalizer::class);
    $approvalProperties = [
        'stage' => 2,
        'from_status' => 'in_review',
        'to_status' => 'in_review',
        'responsibility' => 'stage2_reviewer_user_id',
        'expected_responsible_user_id' => $scenario['actor']->getKey(),
    ];
    $modelOld = ['current_stage' => 2, 'status' => 'in_review'];
    $modelAfter = ['current_stage' => 3, 'status' => 'in_review'];

    $oneExplicit = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 11:00:02',
        $approvalProperties,
        $scenario['actor'],
    );
    $nearestModel = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 11:00:01',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $otherModel = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 11:00:00',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $oneToTwo = $normalizer->normalizeMany(
        collect([$oneExplicit, $nearestModel, $otherModel]),
        $scenario['measurement'],
    );

    $firstExplicit = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 12:00:00',
        $approvalProperties,
        $scenario['actor'],
    );
    $secondExplicit = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 12:00:04',
        $approvalProperties,
        $scenario['actor'],
    );
    $firstModel = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 12:00:00',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $secondModel = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 12:00:04',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $twoToTwo = $normalizer->normalizeMany(
        collect([$firstExplicit, $secondExplicit, $firstModel, $secondModel]),
        $scenario['measurement'],
    );

    expect($oneToTwo)->toHaveCount(2)
        ->and($oneToTwo->where('sourceType', MeasurementHistorySourceType::WorkflowActivity))->toHaveCount(1)
        ->and($oneToTwo->where('sourceType', MeasurementHistorySourceType::ModelActivity))->toHaveCount(1)
        ->and($oneToTwo->firstWhere('sourceType', MeasurementHistorySourceType::ModelActivity)?->sourceActivityId)
        ->toBe($otherModel->getKey())
        ->and($twoToTwo)->toHaveCount(2)
        ->and($twoToTwo->every(
            fn (MeasurementCycleEvent $event): bool => $event->sourceType === MeasurementHistorySourceType::WorkflowActivity,
        ))->toBeTrue();
});

it('preserves rapid reentry transitions and refuses to break an equidistant deduplication tie', function () {
    $scenario = p3bNormalizerScenario();
    $normalizer = app(MeasurementCycleEventNormalizer::class);
    $approvalProperties = [
        'stage' => 2,
        'from_status' => 'in_review',
        'to_status' => 'in_review',
        'responsibility' => 'stage2_reviewer_user_id',
        'expected_responsible_user_id' => $scenario['actor']->getKey(),
    ];
    $modelOld = ['current_stage' => 2, 'status' => 'in_review'];
    $modelAfter = ['current_stage' => 3, 'status' => 'in_review'];
    $firstApproval = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 13:00:00',
        $approvalProperties,
        $scenario['actor'],
    );
    $firstApprovalModel = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 13:00:00',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $return = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_rejected',
        '2026-08-29 13:00:01',
        [
            'stage' => 3,
            'target_stage' => 2,
            'from_status' => 'in_review',
            'to_status' => 'in_review',
            'notes' => 'Reentrada',
            'responsibility' => 'stage3_reviewer_user_id',
            'expected_responsible_user_id' => $scenario['actor']->getKey(),
        ],
        $scenario['actor'],
    );
    $secondApproval = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 13:00:02',
        $approvalProperties,
        $scenario['actor'],
    );
    $secondApprovalModel = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 13:00:02',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $rapidReentry = $normalizer->normalizeMany(
        collect([$firstApproval, $firstApprovalModel, $return, $secondApproval, $secondApprovalModel]),
        $scenario['measurement'],
    );

    $tiedExplicit = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 14:00:02',
        $approvalProperties,
        $scenario['actor'],
    );
    $leftTie = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 14:00:01',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $rightTie = p3bNormalizerModelActivity(
        $scenario['measurement'],
        '2026-08-29 14:00:03',
        $modelOld,
        $modelAfter,
        $scenario['actor'],
    );
    $tie = $normalizer->normalizeMany(
        collect([$tiedExplicit, $leftTie, $rightTie]),
        $scenario['measurement'],
    );

    expect($rapidReentry)->toHaveCount(3)
        ->and($rapidReentry->where('eventType', MeasurementCycleEventType::StageApproved))->toHaveCount(2)
        ->and($rapidReentry->where('eventType', MeasurementCycleEventType::StageRejected))->toHaveCount(1)
        ->and($tie)->toHaveCount(3)
        ->and($tie->where('sourceType', MeasurementHistorySourceType::ModelActivity))->toHaveCount(2)
        ->and($tie->where('sourceType', MeasurementHistorySourceType::ModelActivity)->every(
            fn (MeasurementCycleEvent $event): bool => in_array('deduplication_ambiguous', $event->missingReasons, true),
        ))->toBeTrue();
});

it('uses review actor fallback only when the current decision is compatible', function () {
    $scenario = p3bNormalizerScenario();
    $reviewer = User::factory()->create();
    $review = $scenario['measurement']->reviews()->create([
        'stage' => 2,
        'reviewer_user_id' => $reviewer->getKey(),
        'status' => 'rejected',
        'reviewed_at' => CarbonImmutable::parse('2026-08-29 15:00:00'),
    ]);
    $scenario['measurement']->load('reviews');
    $approval = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 15:00:00',
        [
            'stage' => 2,
            'from_status' => 'in_review',
            'to_status' => 'in_review',
            'responsibility' => 'stage2_reviewer_user_id',
            'expected_responsible_user_id' => $reviewer->getKey(),
            'actual_actor_user_id' => null,
        ],
    );
    $approvalEvent = app(MeasurementCycleEventNormalizer::class)->normalizeMany(
        collect([$approval]),
        $scenario['measurement'],
    )->first();

    $review->forceFill([
        'status' => 'approved',
        'reviewed_at' => CarbonImmutable::parse('2026-08-29 15:01:00'),
    ])->save();
    $scenario['measurement']->unsetRelation('reviews')->load('reviews');
    $rejection = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_rejected',
        '2026-08-29 15:01:00',
        [
            'stage' => 2,
            'target_stage' => 1,
            'from_status' => 'in_review',
            'to_status' => 'in_review',
            'notes' => 'Retorno',
            'responsibility' => 'stage2_reviewer_user_id',
            'expected_responsible_user_id' => $reviewer->getKey(),
            'actual_actor_user_id' => null,
        ],
    );
    $rejectionEvent = app(MeasurementCycleEventNormalizer::class)->normalizeMany(
        collect([$rejection]),
        $scenario['measurement'],
    )->first();

    expect($approvalEvent?->actorId)->toBeNull()
        ->and($approvalEvent?->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($approvalEvent?->missingReasons)->toContain('actor_unknown')
        ->and($rejectionEvent?->actorId)->toBeNull()
        ->and($rejectionEvent?->missingReasons)->toContain('actor_unknown');
});

it('does not assign a mutable review actor to an older decision after stage reentry', function () {
    $scenario = p3bNormalizerScenario();
    $firstReviewer = User::factory()->create();
    $secondReviewer = User::factory()->create();
    $review = $scenario['measurement']->reviews()->create([
        'stage' => 2,
        'reviewer_user_id' => $firstReviewer->getKey(),
        'status' => 'approved',
        'reviewed_at' => CarbonImmutable::parse('2026-08-29 16:01:00'),
    ]);
    $submitted = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_submitted',
        '2026-08-29 16:00:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        $scenario['actor'],
    );
    $stageOneApproval = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 16:00:30',
        ['stage' => 1, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        $scenario['actor'],
    );
    $oldApproval = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 16:01:00',
        ['stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $firstReviewer->getKey(), 'actual_actor_user_id' => null],
    );
    $return = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_rejected',
        '2026-08-29 16:02:00',
        ['stage' => 3, 'target_stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'notes' => 'Reentrada', 'responsibility' => 'stage3_reviewer_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        $scenario['actor'],
    );
    $newApproval = p3bNormalizerWorkflowActivity(
        $scenario['measurement'],
        'measurement_stage_approved',
        '2026-08-29 16:03:00',
        ['stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $secondReviewer->getKey(), 'actual_actor_user_id' => null],
    );
    $review->forceFill([
        'reviewer_user_id' => $secondReviewer->getKey(),
        'status' => 'approved',
        'reviewed_at' => CarbonImmutable::parse('2026-08-29 16:03:00'),
    ])->save();
    $scenario['measurement']->load('reviews');

    $events = app(MeasurementCycleEventNormalizer::class)->normalizeMany(
        collect([$submitted, $stageOneApproval, $oldApproval, $return, $newApproval]),
        $scenario['measurement'],
    );
    $oldEvent = $events->firstWhere('sourceActivityId', $oldApproval->getKey());
    $newEvent = $events->firstWhere('sourceActivityId', $newApproval->getKey());

    expect($oldEvent?->actorId)->toBeNull()
        ->and($oldEvent?->missingReasons)->toContain('actor_unknown')
        ->and($oldEvent?->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($newEvent?->actorId)->toBe($secondReviewer->getKey())
        ->and($newEvent?->missingReasons)->toContain('actor_from_durable_fallback');
});
