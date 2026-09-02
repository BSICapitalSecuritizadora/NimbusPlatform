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

    expect($normalizer->normalize($explicit, $scenario['measurement'])?->actorId)->toBe($scenario['actor']->getKey())
        ->and($normalizer->normalize($causerOnly, $scenario['measurement'])?->actorId)->toBe($scenario['actor']->getKey())
        ->and($normalizer->normalize($mismatch, $scenario['measurement'])?->actorId)->toBe($scenario['actor']->getKey())
        ->and($normalizer->normalize($mismatch, $scenario['measurement'])?->missingReasons)->toContain('actual_actor_causer_mismatch')
        ->and($normalizer->normalize($unknown, null)?->actorId)->toBeNull()
        ->and($normalizer->normalize($unknown, null)?->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($normalizer->normalize($durableFallback, $scenario['measurement'])?->actorId)->toBe($other->getKey())
        ->and($normalizer->normalize($durableFallback, $scenario['measurement'])?->missingReasons)->toContain('actor_from_durable_fallback');
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
