<?php

use App\Enums\DelegationIneffectivenessReason;
use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\ResponsibilityDelegationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Uma delegação vigente e não revogada pode não valer nada, e até aqui a
 * interface dizia só "Ineficaz". A causa sempre esteve ao alcance de quem decide
 * a efetividade -- estes testes fixam que ela agora sai de lá, uma por condição,
 * e que a decisão de autorização não mudou por causa disso.
 *
 * As asserções são sobre o `value` do enum, não sobre o rótulo em português: o
 * código é contrato, o texto é apresentação.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function reasonParticipant(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'delegations.create', 'measurements.review', 'measurements.pay',
        'measurements.receipts', 'measurements.finalize',
    ]);

    return $user;
}

function reasonOperationOf(User $holder): Operation
{
    return Operation::factory()->create(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $holder->getKey(),
    ));
}

/**
 * Um cenário efetivo, do qual cada teste quebra exatamente uma condição.
 *
 * @return array{delegator: User, delegate: User, operation: Operation, delegation: ResponsibilityDelegation}
 */
function effectiveScenario(): array
{
    $delegator = reasonParticipant();
    $delegate = reasonParticipant();
    $operation = reasonOperationOf($delegator);

    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias',
    ], $delegator);

    return compact('delegator', 'delegate', 'operation', 'delegation');
}

/** @return array{status: string, reason: ?string} */
function effectivenessOf(ResponsibilityDelegation $delegation): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Instância nova de propósito: o memo é por instância, e cada asserção quer
    // o estado do banco agora, não o de antes de o teste quebrar a condição.
    $effectiveness = (new ResponsibilityDelegationService)->effectiveness($delegation->fresh());

    return ['status' => $effectiveness->status, 'reason' => $effectiveness->reason?->value];
}

it('reports an effective delegation with no reason to explain', function () {
    $scenario = effectiveScenario();

    expect(effectivenessOf($scenario['delegation']))->toBe(['status' => 'active', 'reason' => null]);
});

it('reports the window states without an ineffectiveness reason', function () {
    $delegator = reasonParticipant();
    $delegate = reasonParticipant();
    reasonOperationOf($delegator);
    $common = ['delegator_user_id' => $delegator->getKey(), 'delegate_user_id' => $delegate->getKey()];

    $scheduled = ResponsibilityDelegation::factory()->create($common + [
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(9),
    ]);
    $expired = ResponsibilityDelegation::factory()->expired()->create($common);
    $revoked = ResponsibilityDelegation::factory()->active()->revoked()->create($common);

    expect(effectivenessOf($scheduled))->toBe(['status' => 'scheduled', 'reason' => null])
        ->and(effectivenessOf($expired))->toBe(['status' => 'expired', 'reason' => null])
        ->and(effectivenessOf($revoked))->toBe(['status' => 'revoked', 'reason' => null]);
});

it('explains an inactive delegator', function () {
    $scenario = effectiveScenario();
    $scenario['delegator']->forceFill(['is_active' => false])->save();

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegatorInactive->value]);
});

it('explains an unapproved delegator', function () {
    $scenario = effectiveScenario();
    $scenario['delegator']->forceFill(['approved_at' => null])->save();

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegatorUnapproved->value]);
});

it('explains a delegator who lost the required permission', function () {
    $scenario = effectiveScenario();
    $scenario['delegator']->revokePermissionTo([
        'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegatorMissingPermission->value]);
});

it('explains a delegator who no longer holds the responsibility', function () {
    $scenario = effectiveScenario();
    $stranger = reasonParticipant();

    // A operação continua existindo; o delegante é que deixou de ser o
    // responsável direto por ela.
    $scenario['operation']->forceFill(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $stranger->getKey(),
    ))->save();

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegatorMissingAssignment->value]);
});

it('explains an inactive delegate', function () {
    $scenario = effectiveScenario();
    $scenario['delegate']->forceFill(['is_active' => false])->save();

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegateInactive->value]);
});

it('explains an unapproved delegate', function () {
    $scenario = effectiveScenario();
    $scenario['delegate']->forceFill(['approved_at' => null])->save();

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegateUnapproved->value]);
});

it('explains a delegate without the required permission', function () {
    $scenario = effectiveScenario();
    $scenario['delegate']->revokePermissionTo([
        'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    expect(effectivenessOf($scenario['delegation']))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::DelegateMissingPermission->value]);
});

it('explains a scope that covers no measurement responsibility', function () {
    $delegator = reasonParticipant();
    $delegate = reasonParticipant();
    reasonOperationOf($delegator);

    // Escopo que o serviço nunca cria, mas que uma linha antiga ou uma carga
    // direta pode ter: etapa fora das cinco do fluxo.
    $delegation = ResponsibilityDelegation::factory()->active()->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_stage' => 9,
        'scope_responsibility' => null,
    ]);

    expect(effectivenessOf($delegation))
        ->toBe(['status' => 'ineffective', 'reason' => DelegationIneffectivenessReason::ScopeMismatch->value]);
});

it('keeps the authorization decision identical to the status it reports', function () {
    $scenario = effectiveScenario();
    $service = app(ResponsibilityDelegationService::class);

    expect($service->effectiveStatus($scenario['delegation']))->toBe('active')
        ->and($service->activeDelegationFor(
            $scenario['delegate'],
            $scenario['operation'],
            MeasurementResponsibility::EngineeringReviewer,
        ))->not->toBeNull();

    $scenario['delegate']->forceFill(['is_active' => false])->save();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fresh = app(ResponsibilityDelegationService::class);

    expect($fresh->effectiveStatus($scenario['delegation']->fresh()))->toBe('ineffective')
        ->and($fresh->activeDelegationFor(
            $scenario['delegate']->fresh(),
            $scenario['operation'],
            MeasurementResponsibility::EngineeringReviewer,
        ))->toBeNull();
});

it('gives every reason a label of its own', function () {
    $labels = array_map(
        fn (DelegationIneffectivenessReason $reason): string => $reason->label(),
        DelegationIneffectivenessReason::cases(),
    );

    expect($labels)->toHaveCount(count(array_unique($labels)))
        ->and($labels)->each->not->toBeEmpty();
});
