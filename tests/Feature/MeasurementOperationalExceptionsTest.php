<?php

use App\Enums\AccessPermission;
use App\Enums\MeasurementOperationalExceptionType;
use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\MeasurementExceptions\MeasurementExceptionResource;
use App\Filament\Resources\MeasurementExceptions\Pages\ListMeasurementExceptions;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementOperationalExceptionReadModel;
use App\Services\MeasurementSlaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-31 12:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['measurements.sla.calendar_code' => null]);

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @param list<string> $extraPermissions */
function p3ExceptionUser(array $extraPermissions = []): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo(array_values(array_unique([
        AccessPermission::OperationsView->value,
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsExceptionsView->value,
        AccessPermission::MeasurementsReview->value,
        AccessPermission::MeasurementsPay->value,
        AccessPermission::MeasurementsReceipts->value,
        AccessPermission::MeasurementsFinalize->value,
        ...$extraPermissions,
    ])));

    return $user;
}

/** @param array<string, mixed> $attributes */
function p3ExceptionOperation(User $viewer, array $attributes = []): Operation
{
    return Operation::factory()->create(array_merge([
        'assigned_user_id' => $viewer->getKey(),
        'responsible_user_id' => $viewer->getKey(),
        'stage2_reviewer_user_id' => $viewer->getKey(),
        'stage3_reviewer_user_id' => $viewer->getKey(),
        'payment_manager_user_id' => $viewer->getKey(),
        'payment_receipt_uploader_user_id' => $viewer->getKey(),
        'payment_finalizer_user_id' => $viewer->getKey(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function p3ExceptionMeasurement(
    Operation $operation,
    array $attributes = [],
    bool $withPendingReview = true,
): Measurement {
    $measurement = Measurement::factory()->create(array_merge([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
        'reference_month' => '2026-08-01',
    ], $attributes));

    if ($withPendingReview && in_array($measurement->status, [
        'pending',
        'in_review',
        'paused',
        'awaiting_payment',
    ], true)) {
        $measurement->reviews()->create([
            'stage' => $measurement->current_stage,
            'status' => 'pending',
            'created_at' => now()->subDay(),
        ]);
    }

    return $measurement;
}

/** @return list<string> */
function p3ExceptionTypesFor(User $actor, array $filters = []): array
{
    return collect(app(MeasurementOperationalExceptionReadModel::class)->scanFor(
        $actor,
        $filters,
        perPage: 100,
    )->items)->pluck('type.value')->all();
}

it('requires the P3A permission in addition to measurements view', function (): void {
    $withoutP3A = User::factory()->create();
    $withoutP3A->givePermissionTo(AccessPermission::MeasurementsView->value);

    $withoutMeasurementView = User::factory()->create();
    $withoutMeasurementView->givePermissionTo(AccessPermission::MeasurementsExceptionsView->value);

    foreach ([$withoutP3A, $withoutMeasurementView] as $actor) {
        $this->actingAs($actor);

        expect(MeasurementExceptionResource::canViewAny())->toBeFalse()
            ->and(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(0);
    }
});

it('allows the resource only when both read permissions are present', function (): void {
    $actor = p3ExceptionUser();
    $this->actingAs($actor);

    expect(MeasurementExceptionResource::canViewAny())->toBeTrue();

    $this->get(MeasurementExceptionResource::getUrl('index'))->assertOk();
});

it('keeps the resource completely read only with explicit gates', function (): void {
    $actor = p3ExceptionUser();
    $measurement = p3ExceptionMeasurement(p3ExceptionOperation($actor));
    $this->actingAs($actor);

    expect(MeasurementExceptionResource::canCreate())->toBeFalse()
        ->and(MeasurementExceptionResource::canEdit($measurement))->toBeFalse()
        ->and(MeasurementExceptionResource::canDelete($measurement))->toBeFalse()
        ->and(MeasurementExceptionResource::canDeleteAny())->toBeFalse()
        ->and(MeasurementExceptionResource::canForceDelete($measurement))->toBeFalse()
        ->and(MeasurementExceptionResource::canForceDeleteAny())->toBeFalse()
        ->and(MeasurementExceptionResource::canRestore($measurement))->toBeFalse()
        ->and(MeasurementExceptionResource::canRestoreAny())->toBeFalse()
        ->and(MeasurementExceptionResource::canReplicate($measurement))->toBeFalse();
});

it('classifies the permanent responsibility required by each actionable stage and status', function (
    string $status,
    int $stage,
    string $operationColumn,
    MeasurementResponsibility $responsibility,
    MeasurementOperationalExceptionType $type,
): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor, [$operationColumn => null]);
    p3ExceptionMeasurement($operation, [
        'status' => $status,
        'current_stage' => $stage,
    ], ! in_array($status, ['awaiting_receipt', 'approved'], true));

    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor);

    expect($result->total)->toBe(1)
        ->and($result->items[0]->type)->toBe($type)
        ->and($result->items[0]->expectedResponsibility)->toBe($responsibility);
})->with([
    'engineering' => ['in_review', 1, 'responsible_user_id', MeasurementResponsibility::EngineeringReviewer, MeasurementOperationalExceptionType::MissingCurrentStageResponsible],
    'management' => ['in_review', 2, 'stage2_reviewer_user_id', MeasurementResponsibility::ManagementReviewer, MeasurementOperationalExceptionType::MissingCurrentStageResponsible],
    'compliance' => ['in_review', 3, 'stage3_reviewer_user_id', MeasurementResponsibility::ComplianceReviewer, MeasurementOperationalExceptionType::MissingCurrentStageResponsible],
    'payment' => ['awaiting_payment', 4, 'payment_manager_user_id', MeasurementResponsibility::PaymentManager, MeasurementOperationalExceptionType::MissingPaymentManager],
    'receipt' => ['awaiting_receipt', 5, 'payment_receipt_uploader_user_id', MeasurementResponsibility::ReceiptUploader, MeasurementOperationalExceptionType::MissingReceiptUploader],
    'finalization' => ['approved', 5, 'payment_finalizer_user_id', MeasurementResponsibility::Finalizer, MeasurementOperationalExceptionType::MissingFinalizer],
]);

it('does not report future or historical responsibilities outside the current actionable state', function (
    string $status,
    int $stage,
    string $missingColumn,
): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor, [$missingColumn => null]);
    p3ExceptionMeasurement($operation, [
        'status' => $status,
        'current_stage' => $stage,
    ], ! in_array($status, ['awaiting_receipt', 'approved', 'finalized'], true));

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(0);
})->with([
    'future payment manager' => ['in_review', 1, 'payment_manager_user_id'],
    'future receipt uploader' => ['awaiting_payment', 4, 'payment_receipt_uploader_user_id'],
    'future finalizer' => ['awaiting_receipt', 5, 'payment_finalizer_user_id'],
    'finalized measurement' => ['finalized', 5, 'payment_finalizer_user_id'],
]);

it('treats an inactive permanent responsible as structurally unavailable', function (): void {
    $actor = p3ExceptionUser();
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->givePermissionTo(MeasurementResponsibility::ManagementReviewer->permission());
    $operation = p3ExceptionOperation($actor, ['stage2_reviewer_user_id' => $inactive->getKey()]);
    p3ExceptionMeasurement($operation, ['current_stage' => 2]);

    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor);

    expect($result->total)->toBe(1)
        ->and($result->items[0]->type)->toBe(MeasurementOperationalExceptionType::MissingCurrentStageResponsible)
        ->and($result->items[0]->configuredResponsibleName)->toBe($inactive->name.' (inativo)');
});

it('requires operational approval and the canonical permission for every permanent responsibility', function (
    string $status,
    int $stage,
    string $operationColumn,
    MeasurementResponsibility $responsibility,
    MeasurementOperationalExceptionType $type,
): void {
    $actor = p3ExceptionUser();
    $responsible = User::factory()->create();
    $responsible->givePermissionTo($responsibility->permission());
    $operation = p3ExceptionOperation($actor, [$operationColumn => $responsible->getKey()]);
    p3ExceptionMeasurement($operation, [
        'status' => $status,
        'current_stage' => $stage,
    ], ! in_array($status, ['awaiting_receipt', 'approved'], true));
    $readModel = app(MeasurementOperationalExceptionReadModel::class);

    expect($readModel->scanFor($actor)->total)->toBe(0);

    $responsible->revokePermissionTo($responsibility->permission());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $withoutPermission = $readModel->scanFor($actor);

    expect($withoutPermission->total)->toBe(1)
        ->and($withoutPermission->items[0]->type)->toBe($type)
        ->and($withoutPermission->items[0]->configuredResponsibleName)
        ->toBe($responsible->name.' (sem permissão operacional)');

    $responsible->givePermissionTo($responsibility->permission());
    $responsible->forceFill(['approved_at' => null])->saveQuietly();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $unapproved = $readModel->scanFor($actor);

    expect($unapproved->total)->toBe(1)
        ->and($unapproved->items[0]->type)->toBe($type)
        ->and($unapproved->items[0]->configuredResponsibleName)
        ->toBe($responsible->name.' (não aprovado)');

    $responsible->forceFill(['approved_at' => now()])->saveQuietly();

    expect($readModel->scanFor($actor)->total)->toBe(0);
})->with([
    'engineering' => ['in_review', 1, 'responsible_user_id', MeasurementResponsibility::EngineeringReviewer, MeasurementOperationalExceptionType::MissingCurrentStageResponsible],
    'management' => ['in_review', 2, 'stage2_reviewer_user_id', MeasurementResponsibility::ManagementReviewer, MeasurementOperationalExceptionType::MissingCurrentStageResponsible],
    'compliance' => ['in_review', 3, 'stage3_reviewer_user_id', MeasurementResponsibility::ComplianceReviewer, MeasurementOperationalExceptionType::MissingCurrentStageResponsible],
    'payment' => ['awaiting_payment', 4, 'payment_manager_user_id', MeasurementResponsibility::PaymentManager, MeasurementOperationalExceptionType::MissingPaymentManager],
    'receipt' => ['awaiting_receipt', 5, 'payment_receipt_uploader_user_id', MeasurementResponsibility::ReceiptUploader, MeasurementOperationalExceptionType::MissingReceiptUploader],
    'finalization' => ['approved', 5, 'payment_finalizer_user_id', MeasurementResponsibility::Finalizer, MeasurementOperationalExceptionType::MissingFinalizer],
]);

it('does not accept an unrelated measurement permission for the payment manager', function (): void {
    $actor = p3ExceptionUser();
    $paymentManager = User::factory()->create();
    $paymentManager->givePermissionTo(AccessPermission::MeasurementsReview->value);
    $operation = p3ExceptionOperation($actor, ['payment_manager_user_id' => $paymentManager->getKey()]);
    p3ExceptionMeasurement($operation, [
        'status' => 'awaiting_payment',
        'current_stage' => 4,
    ]);

    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor);

    expect($result->total)->toBe(1)
        ->and($result->items[0]->type)->toBe(MeasurementOperationalExceptionType::MissingPaymentManager)
        ->and($result->items[0]->configuredResponsibleName)
        ->toBe($paymentManager->name.' (sem permissão operacional)');
});

it('projects SLA not configured only from the canonical SLA service result', function (): void {
    SlaConfiguration::query()->where('stage', 2)->delete();
    config(['measurements.sla.stage_deadlines.2' => null]);

    $actor = p3ExceptionUser();
    $measurement = p3ExceptionMeasurement(p3ExceptionOperation($actor), ['current_stage' => 2]);
    $loaded = $measurement->fresh(['operation', 'reviews', 'pauses']);

    expect(app(MeasurementSlaService::class)->evaluate($loaded)['status'])
        ->toBe(MeasurementSlaService::STATUS_NOT_CONFIGURED)
        ->and(p3ExceptionTypesFor($actor))
        ->toBe([MeasurementOperationalExceptionType::SlaNotConfigured->value]);
});

it('projects invalid SLA configuration only from the canonical SLA service result', function (): void {
    SlaConfiguration::query()->where('stage', 3)->update(['duration_unit' => 'minutes']);

    $actor = p3ExceptionUser();
    $measurement = p3ExceptionMeasurement(p3ExceptionOperation($actor), ['current_stage' => 3]);
    $loaded = $measurement->fresh(['operation', 'reviews', 'pauses']);

    expect(app(MeasurementSlaService::class)->evaluate($loaded)['status'])
        ->toBe(MeasurementSlaService::STATUS_INVALID_CONFIG)
        ->and(p3ExceptionTypesFor($actor))
        ->toBe([MeasurementOperationalExceptionType::SlaInvalidConfig->value]);
});

it('does not duplicate ordinary SLA states already represented by P2', function (): void {
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);

    $actor = p3ExceptionUser();
    $measurement = p3ExceptionMeasurement(p3ExceptionOperation($actor));
    $measurement->reviews()->update(['created_at' => now()->subDays(10)]);
    $loaded = $measurement->fresh(['operation', 'reviews', 'pauses']);

    expect(app(MeasurementSlaService::class)->evaluate($loaded)['status'])
        ->toBe(MeasurementSlaService::STATUS_OVERDUE)
        ->and(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)
        ->toBe(0)
        ->and(MeasurementOperationalExceptionType::options())
        ->not->toHaveKeys([
            MeasurementSlaService::STATUS_OVERDUE,
            MeasurementSlaService::STATUS_APPROACHING,
            MeasurementSlaService::STATUS_PAUSED,
            MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE,
        ]);
});

it('represents multiple exceptions as separate rows for the same measurement', function (): void {
    SlaConfiguration::query()->where('stage', 1)->delete();
    config(['measurements.sla.stage_deadlines.1' => null]);

    $actor = p3ExceptionUser();
    $measurement = p3ExceptionMeasurement(p3ExceptionOperation($actor, ['responsible_user_id' => null]));
    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor);

    expect($result->total)->toBe(2)
        ->and(collect($result->items)->pluck('measurement.id')->unique()->all())
        ->toBe([$measurement->getKey()])
        ->and(collect($result->items)->pluck('type')->all())
        ->toContain(
            MeasurementOperationalExceptionType::MissingCurrentStageResponsible,
            MeasurementOperationalExceptionType::SlaNotConfigured,
        );
});

it('removes a derived exception after the canonical configuration is corrected', function (): void {
    $actor = p3ExceptionUser();
    $replacement = User::factory()->create();
    $replacement->givePermissionTo(MeasurementResponsibility::EngineeringReviewer->permission());
    $operation = p3ExceptionOperation($actor, ['responsible_user_id' => null]);
    p3ExceptionMeasurement($operation);

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(1);

    $operation->forceFill(['responsible_user_id' => $replacement->getKey()])->saveQuietly();

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(0);
});

it('removes an SLA exception after the canonical configuration is corrected', function (): void {
    SlaConfiguration::query()->where('stage', 2)->delete();
    config(['measurements.sla.stage_deadlines.2' => null]);

    $actor = p3ExceptionUser();
    p3ExceptionMeasurement(p3ExceptionOperation($actor), ['current_stage' => 2]);

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(1);

    SlaConfiguration::factory()->forStage(2, 5)->create();

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(0);
});

it('scopes the population to direct visibility and hides unrelated measurements', function (): void {
    $actor = p3ExceptionUser();
    $other = p3ExceptionUser();
    $visible = p3ExceptionMeasurement(p3ExceptionOperation($actor, ['responsible_user_id' => null]));
    $hidden = p3ExceptionMeasurement(p3ExceptionOperation($other, ['responsible_user_id' => null]));

    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor);
    $measurementIds = collect($result->items)->pluck('measurement.id');

    expect($measurementIds)->toContain($visible->getKey())
        ->not->toContain($hidden->getKey());
});

it('inherits delegated visibility without using delegation to mask a missing permanent role', function (): void {
    $delegator = p3ExceptionUser();
    $delegate = p3ExceptionUser();
    $operation = p3ExceptionOperation($delegator, [
        'assigned_user_id' => null,
        'stage2_reviewer_user_id' => null,
    ]);
    $measurement = p3ExceptionMeasurement($operation, ['current_stage' => 2]);

    ResponsibilityDelegation::factory()
        ->active()
        ->forStage(1, $operation, MeasurementResponsibility::EngineeringReviewer)
        ->create([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
        ]);

    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($delegate);

    expect($result->total)->toBe(1)
        ->and($result->items[0]->measurement->is($measurement))->toBeTrue()
        ->and($result->items[0]->expectedResponsibility)->toBe(MeasurementResponsibility::ManagementReviewer);
});

it('rejects expired and revoked delegations from the P3A population', function (string $state): void {
    $delegator = p3ExceptionUser();
    $delegate = p3ExceptionUser();
    $operation = p3ExceptionOperation($delegator, [
        'assigned_user_id' => null,
        'stage2_reviewer_user_id' => null,
    ]);
    p3ExceptionMeasurement($operation, ['current_stage' => 2]);

    $factory = ResponsibilityDelegation::factory()
        ->forStage(1, $operation, MeasurementResponsibility::EngineeringReviewer);

    ($state === 'expired' ? $factory->expired() : $factory->active()->revoked())->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($delegate)->total)->toBe(0);
})->with(['expired', 'revoked']);

it('removes access immediately when the P3A permission is lost', function (): void {
    $actor = p3ExceptionUser();
    p3ExceptionMeasurement(p3ExceptionOperation($actor, ['responsible_user_id' => null]));

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor)->total)->toBe(1);

    $actor->revokePermissionTo(AccessPermission::MeasurementsExceptionsView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor->fresh())->total)->toBe(0);
});

it('uses the existing administrator visibility override instead of a P3A global query', function (): void {
    $participant = p3ExceptionUser();
    $operation = p3ExceptionOperation($participant, [
        'assigned_user_id' => null,
        'responsible_user_id' => null,
    ]);
    $measurement = p3ExceptionMeasurement($operation);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $result = app(MeasurementOperationalExceptionReadModel::class)->scanFor($admin);

    expect(collect($result->items)->pluck('measurement.id'))
        ->toContain($measurement->getKey());
});

it('derives operation and emission options only from the visible measurement portfolio', function (): void {
    $actor = p3ExceptionUser();
    $other = p3ExceptionUser();
    $visibleEmission = Emission::factory()->create(['name' => 'Emissão visível']);
    $hiddenEmission = Emission::factory()->create(['name' => 'Emissão restrita']);
    $visibleOperation = p3ExceptionOperation($actor, ['emission_id' => $visibleEmission->getKey()]);
    $hiddenOperation = p3ExceptionOperation($other, ['emission_id' => $hiddenEmission->getKey()]);
    p3ExceptionMeasurement($visibleOperation);
    p3ExceptionMeasurement($hiddenOperation);

    $readModel = app(MeasurementOperationalExceptionReadModel::class);

    expect($readModel->operationOptionsFor($actor))
        ->toHaveKey($visibleOperation->getKey())
        ->not->toHaveKey($hiddenOperation->getKey())
        ->and($readModel->emissionOptionsFor($actor))
        ->toHaveKey($visibleEmission->getKey())
        ->not->toHaveKey($hiddenEmission->getKey());
});

it('intersects operation emission competence stage exception and search filters', function (): void {
    $actor = p3ExceptionUser();
    $firstEmission = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $secondEmission = Emission::factory()->create(['name' => 'Emissão Beta']);
    $firstOperation = p3ExceptionOperation($actor, [
        'emission_id' => $firstEmission->getKey(),
        'title' => 'Operação Horizonte',
        'stage2_reviewer_user_id' => null,
    ]);
    $secondOperation = p3ExceptionOperation($actor, [
        'emission_id' => $secondEmission->getKey(),
        'title' => 'Operação Atlântico',
        'stage3_reviewer_user_id' => null,
    ]);
    $matching = p3ExceptionMeasurement($firstOperation, [
        'filename' => 'medicao-horizonte.pdf',
        'current_stage' => 2,
        'reference_month' => '2026-08-01',
    ]);
    p3ExceptionMeasurement($firstOperation, [
        'filename' => 'medicao-julho.pdf',
        'current_stage' => 2,
        'reference_month' => '2026-07-01',
    ]);
    p3ExceptionMeasurement($secondOperation, [
        'filename' => 'medicao-atlantico.pdf',
        'current_stage' => 3,
        'reference_month' => '2026-08-01',
    ]);

    $baseFilters = [
        'operation_id' => (string) $firstOperation->getKey(),
        'emission_id' => (string) $firstEmission->getKey(),
        'competence_from' => '2026-08-01',
        'competence_to' => '2026-08-31',
        'stage' => '2',
    ];
    $base = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, $baseFilters, 'Horizonte');
    $destination = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, [
        ...$baseFilters,
        'exception_type' => MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value,
    ], 'Horizonte');

    expect($base->total)->toBe(1)
        ->and($destination->total)->toBe($base->counts[MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value])
        ->and($destination->items[0]->measurement->is($matching))->toBeTrue();
});

it('applies each workspace filter independently before composing them', function (): void {
    $actor = p3ExceptionUser();
    $alphaEmission = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $betaEmission = Emission::factory()->create(['name' => 'Emissão Beta']);
    $alphaOperation = p3ExceptionOperation($actor, [
        'emission_id' => $alphaEmission->getKey(),
        'stage2_reviewer_user_id' => null,
    ]);
    $betaOperation = p3ExceptionOperation($actor, [
        'emission_id' => $betaEmission->getKey(),
        'payment_manager_user_id' => null,
    ]);
    p3ExceptionMeasurement($alphaOperation, ['current_stage' => 2, 'reference_month' => '2026-08-01']);
    p3ExceptionMeasurement($alphaOperation, ['current_stage' => 2, 'reference_month' => '2026-07-01']);
    p3ExceptionMeasurement($betaOperation, [
        'status' => 'awaiting_payment',
        'current_stage' => 4,
        'reference_month' => '2026-08-01',
    ]);

    $readModel = app(MeasurementOperationalExceptionReadModel::class);

    expect($readModel->scanFor($actor, ['operation_id' => $alphaOperation->getKey()])->total)->toBe(2)
        ->and($readModel->scanFor($actor, ['emission_id' => $betaEmission->getKey()])->total)->toBe(1)
        ->and($readModel->scanFor($actor, [
            'competence_from' => '2026-08-01',
            'competence_to' => '2026-08-31',
        ])->total)->toBe(2)
        ->and($readModel->scanFor($actor, ['stage' => 2])->total)->toBe(2)
        ->and($readModel->scanFor($actor, [
            'exception_type' => MeasurementOperationalExceptionType::MissingPaymentManager->value,
        ])->total)->toBe(1);
});

it('fails closed for malformed filter values instead of widening the portfolio', function (array $filters): void {
    $actor = p3ExceptionUser();
    p3ExceptionMeasurement(p3ExceptionOperation($actor, ['responsible_user_id' => null]));

    expect(app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, $filters)->total)->toBe(0);
})->with([
    'operation' => [['operation_id' => 'invalid']],
    'emission' => [['emission_id' => '-1']],
    'stage' => [['stage' => '6']],
    'competence' => [['competence_from' => '2026-99-99']],
    'exception' => [['exception_type' => 'unknown']],
]);

it('keeps summary counts and exception drill down on exactly the same population', function (): void {
    SlaConfiguration::query()->where('stage', 4)->update(['duration_unit' => 'minutes']);

    $actor = p3ExceptionUser();
    $measurement = p3ExceptionMeasurement(
        p3ExceptionOperation($actor, ['payment_manager_user_id' => null]),
        ['status' => 'awaiting_payment', 'current_stage' => 4],
    );
    $readModel = app(MeasurementOperationalExceptionReadModel::class);
    $summary = $readModel->scanFor($actor);

    expect($summary->total)->toBe(2)
        ->and($summary->counts[MeasurementOperationalExceptionType::MissingPaymentManager->value])->toBe(1)
        ->and($summary->counts[MeasurementOperationalExceptionType::SlaInvalidConfig->value])->toBe(1);

    foreach ([
        MeasurementOperationalExceptionType::MissingPaymentManager,
        MeasurementOperationalExceptionType::SlaInvalidConfig,
    ] as $type) {
        $destination = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, [
            'exception_type' => $type->value,
        ]);

        expect($destination->total)->toBe($summary->counts[$type->value])
            ->and($destination->items[0]->measurement->is($measurement))->toBeTrue();
    }

    $emptyDestination = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, [
        'exception_type' => MeasurementOperationalExceptionType::SlaNotConfigured->value,
    ]);

    expect($emptyDestination->total)->toBe(0)
        ->and(array_sum($emptyDestination->counts))->toBe(0);
});

it('preserves base filters when a summary card refines the exception type', function (): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor, ['responsible_user_id' => null]);
    p3ExceptionMeasurement($operation, ['reference_month' => '2026-08-01']);
    $this->actingAs($actor);

    Livewire::test(ListMeasurementExceptions::class)
        ->set('operationId', (string) $operation->getKey())
        ->set('competenceFrom', '2026-08-01')
        ->set('competenceTo', '2026-08-31')
        ->call('filterByException', MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value)
        ->assertSet('operationId', (string) $operation->getKey())
        ->assertSet('competenceFrom', '2026-08-01')
        ->assertSet('competenceTo', '2026-08-31')
        ->assertSet('exceptionType', MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value)
        ->call('filterByException', MeasurementOperationalExceptionType::SlaInvalidConfig->value)
        ->assertSet('exceptionType', MeasurementOperationalExceptionType::MissingCurrentStageResponsible->value);
});

it('exposes only policy protected navigation and never performs a correction', function (): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor, ['responsible_user_id' => null]);
    p3ExceptionMeasurement($operation);
    $this->actingAs($actor);

    $component = Livewire::test(ListMeasurementExceptions::class);
    $exception = $component->instance()->result()->items[0];

    expect($component->instance()->measurementUrl($exception))->not->toBeNull()
        ->and($component->instance()->operationUrl($exception))->not->toBeNull()
        ->and($component->instance()->manageResponsibilitiesUrl($exception))->toBeNull();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $adminComponent = Livewire::test(ListMeasurementExceptions::class);
    $adminException = $adminComponent->instance()->result()->items[0];

    expect($adminComponent->instance()->manageResponsibilitiesUrl($adminException))->not->toBeNull();
});

it('does not mutate measurements operations or workflow state while scanning', function (): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor, ['responsible_user_id' => null]);
    $measurement = p3ExceptionMeasurement($operation);
    $measurementBefore = $measurement->fresh()->getAttributes();
    $operationBefore = $operation->fresh()->getAttributes();

    app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor);

    expect($measurement->fresh()->getAttributes())->toBe($measurementBefore)
        ->and($operation->fresh()->getAttributes())->toBe($operationBefore);
});

it('processes 205 visible measurements across more than two chunks without skip or duplication', function (): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor, ['responsible_user_id' => null]);
    $measurements = Measurement::factory()
        ->count(205)
        ->create([
            'operation_id' => $operation->getKey(),
            'status' => 'in_review',
            'current_stage' => 1,
            'reference_month' => '2026-08-01',
        ]);

    $readModel = app(MeasurementOperationalExceptionReadModel::class);
    $firstPage = $readModel->scanFor($actor, perPage: 100);
    $secondPage = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, page: 2, perPage: 100);
    $thirdPage = app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, page: 3, perPage: 100);
    $allIds = collect([$firstPage, $secondPage, $thirdPage])
        ->flatMap(fn ($result) => collect($result->items)->pluck('measurement.id'));

    expect($firstPage->total)->toBe(205)
        ->and($secondPage->total)->toBe(205)
        ->and($thirdPage->total)->toBe(205)
        ->and($allIds)->toHaveCount(205)
        ->and($allIds->unique())->toHaveCount(205)
        ->and($allIds)->toContain(
            $measurements[0]->getKey(),
            $measurements[100]->getKey(),
            $measurements[204]->getKey(),
        );
});

it('keeps permission query growth bounded while evaluating more than two chunks', function (): void {
    $actor = p3ExceptionUser();
    $operation = p3ExceptionOperation($actor);
    Measurement::factory()->count(205)->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(MeasurementOperationalExceptionReadModel::class)->scanFor($actor, perPage: 100);

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(60);
});

it('keeps the P3A implementation structurally isolated and metadata safe', function (): void {
    $resource = File::get(app_path('Filament/Resources/MeasurementExceptions/MeasurementExceptionResource.php'));
    $page = File::get(app_path('Filament/Resources/MeasurementExceptions/Pages/ListMeasurementExceptions.php'));
    $readModel = File::get(app_path('Services/MeasurementOperationalExceptionReadModel.php'));
    $view = File::get(resource_path('views/filament/resources/measurement-exceptions/pages/list-measurement-exceptions.blade.php'));
    $migration = File::get(database_path('migrations/2026_08_31_190000_grant_measurement_exception_view_permission.php'));

    expect($resource)
        ->toContain('canViewAny', 'canCreate', 'canEdit', 'canDelete')
        ->not->toContain('BulkAction', 'CreateAction', 'EditAction', 'DeleteAction')
        ->and($page)
        ->not->toContain('approve(', 'reject(', 'finalize(', 'registerPayment(')
        ->and($readModel)
        ->toMatch('/Measurement::query\(\)\s*->visibleTo\(\$actor\)/')
        ->toContain(
            'MeasurementSlaService $sla',
            '$this->sla->evaluate($measurement)',
            "column: 'measurements.id'",
            "alias: 'id'",
        )
        ->not->toContain('->approve(', '->reject(', '->finalize(', '->registerPayment(')
        ->and($view)
        ->not->toContain('storage_path', 'storage_disk', 'sha256', 'engineering_snapshot', 'receipt_path')
        ->and($migration)
        ->not->toContain('Schema::create', 'measurement_exceptions');
});

it('keeps enum seeder and permission migration coherent for standard roles', function (string $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect(AccessPermission::values())
        ->toContain(AccessPermission::MeasurementsExceptionsView->value)
        ->and($user->can(AccessPermission::MeasurementsExceptionsView->value))->toBeTrue();
})->with(['super-admin', 'admin', 'editor']);

it('does not grant the P3A permission to custom roles', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'operations-custom']);
    $user->assignRole($role);

    expect($user->can(AccessPermission::MeasurementsExceptionsView->value))->toBeFalse();
});
