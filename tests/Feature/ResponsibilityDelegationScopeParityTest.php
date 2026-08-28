<?php

use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\ResponsibilityDelegationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return list<string> */
function operationResponsibilityColumns(): array
{
    return array_map(
        fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
        MeasurementResponsibility::cases(),
    );
}

function delegationParticipant(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'delegations.create', 'measurements.review', 'measurements.pay',
        'measurements.receipts', 'measurements.finalize',
    ]);

    return $user;
}

/**
 * Operação em que apenas a coluna da responsabilidade informada está preenchida,
 * para que a pergunta feita ao PHP e ao SQL seja exatamente a mesma.
 */
function operationHeldOnlyFor(User $holder, MeasurementResponsibility $responsibility): Operation
{
    $operation = Operation::factory()->create();
    $operation->forceFill(array_merge(
        array_fill_keys(operationResponsibilityColumns(), null),
        [$responsibility->operationColumn() => $holder->getKey()],
    ))->save();

    return $operation->fresh();
}

function createDelegationOrFailure(User $delegator, User $delegate, array $scope): string
{
    try {
        app(ResponsibilityDelegationService::class)->createDelegation(array_merge([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(5),
            'reason' => 'Cobertura de férias',
        ], $scope), $delegator);

        return 'created';
    } catch (ValidationException $exception) {
        $message = collect($exception->errors())->flatten()->first();

        return str_contains($message, 'Ciclo') ? 'cycle' : 'conflict';
    }
}

it('mantém covers() em PHP e o SQL de visibilidade respondendo o mesmo para cada escopo', function () {
    $service = app(ResponsibilityDelegationService::class);
    $divergences = [];

    $scopeVariants = [
        'global',
        'operation:match',
        'operation:other',
        'stage:responsibility',
        'stage:null',
        'stage:blank',
        'stage:other-stage',
    ];

    foreach ($scopeVariants as $variant) {
        foreach (MeasurementResponsibility::cases() as $responsibility) {
            $delegator = delegationParticipant();
            $delegate = delegationParticipant();
            $operation = operationHeldOnlyFor($delegator, $responsibility);
            $otherOperation = operationHeldOnlyFor($delegator, $responsibility);
            $otherStage = $responsibility->stage() === 1 ? 2 : 1;

            $scope = match ($variant) {
                'global' => ['scope_type' => 'global', 'scope_operation_id' => null, 'scope_stage' => null, 'scope_responsibility' => null],
                'operation:match' => ['scope_type' => 'operation', 'scope_operation_id' => $operation->getKey(), 'scope_stage' => null, 'scope_responsibility' => null],
                'operation:other' => ['scope_type' => 'operation', 'scope_operation_id' => $otherOperation->getKey(), 'scope_stage' => null, 'scope_responsibility' => null],
                'stage:responsibility' => ['scope_type' => 'stage', 'scope_operation_id' => null, 'scope_stage' => $responsibility->stage(), 'scope_responsibility' => $responsibility->value],
                'stage:null' => ['scope_type' => 'stage', 'scope_operation_id' => null, 'scope_stage' => $responsibility->stage(), 'scope_responsibility' => null],
                'stage:blank' => ['scope_type' => 'stage', 'scope_operation_id' => null, 'scope_stage' => $responsibility->stage(), 'scope_responsibility' => ''],
                'stage:other-stage' => ['scope_type' => 'stage', 'scope_operation_id' => null, 'scope_stage' => $otherStage, 'scope_responsibility' => MeasurementResponsibility::primaryForStage($otherStage)?->value],
            };

            $delegation = ResponsibilityDelegation::factory()->create(array_merge($scope, [
                'delegator_user_id' => $delegator->getKey(),
                'delegate_user_id' => $delegate->getKey(),
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(3),
                'revoked_at' => null,
            ]));

            $php = $delegation->fresh()->covers($operation, $responsibility);
            $sql = $service->hasAnyActiveDelegatedResponsibility($delegate->fresh(), $operation->fresh());

            if ($php !== $sql) {
                $divergences[] = sprintf(
                    '%s / %s: PHP=%s SQL=%s',
                    $variant,
                    $responsibility->value,
                    $php ? 'true' : 'false',
                    $sql ? 'true' : 'false',
                );
            }
        }
    }

    expect($divergences)->toBe([]);
});

it('trata scope_responsibility em branco como escopo que não cobre nada', function () {
    $service = app(ResponsibilityDelegationService::class);
    $responsibility = MeasurementResponsibility::EngineeringReviewer;
    $delegator = delegationParticipant();
    $delegate = delegationParticipant();
    $operation = operationHeldOnlyFor($delegator, $responsibility);

    $delegation = ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_operation_id' => null,
        'scope_stage' => 1,
        'scope_responsibility' => '',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(3),
        'revoked_at' => null,
    ]);

    expect($delegation->fresh()->covers($operation, $responsibility))->toBeFalse()
        ->and($service->hasAnyActiveDelegatedResponsibility($delegate->fresh(), $operation->fresh()))->toBeFalse()
        ->and($service->activeDelegationFor($delegate->fresh(), $operation->fresh(), $responsibility))->toBeNull();
});

it('mantém null como responsabilidade primária da etapa em PHP e em SQL', function () {
    $service = app(ResponsibilityDelegationService::class);
    $primary = MeasurementResponsibility::primaryForStage(5);
    $secondary = MeasurementResponsibility::ReceiptUploader;
    $delegator = delegationParticipant();
    $delegate = delegationParticipant();

    $primaryOperation = operationHeldOnlyFor($delegator, $primary);
    $secondaryOperation = operationHeldOnlyFor($delegator, $secondary);

    foreach ([$primaryOperation, $secondaryOperation] as $operation) {
        ResponsibilityDelegation::factory()->create([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
            'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
            'scope_operation_id' => $operation->getKey(),
            'scope_stage' => 5,
            'scope_responsibility' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'revoked_at' => null,
        ]);
    }

    expect($service->hasAnyActiveDelegatedResponsibility($delegate->fresh(), $primaryOperation->fresh()))->toBeTrue()
        ->and($service->hasAnyActiveDelegatedResponsibility($delegate->fresh(), $secondaryOperation->fresh()))->toBeFalse();
});

it('documenta a classificação atual de conflito de escopo para o mesmo delegante', function (
    array $firstScope,
    array $secondScope,
    bool $sameDelegate,
    string $expected,
) {
    $delegator = delegationParticipant();
    $delegate = delegationParticipant();
    $otherDelegate = delegationParticipant();
    $operationX = Operation::factory()->create(array_fill_keys(operationResponsibilityColumns(), $delegator->getKey()));
    $operationY = Operation::factory()->create(array_fill_keys(operationResponsibilityColumns(), $delegator->getKey()));
    $operations = ['X' => $operationX->getKey(), 'Y' => $operationY->getKey()];

    $resolve = function (array $scope) use ($operations): array {
        if (isset($scope['operation'])) {
            $scope['scope_operation_id'] = $operations[$scope['operation']];
            unset($scope['operation']);
        }

        return $scope;
    };

    expect(createDelegationOrFailure($delegator, $delegate, $resolve($firstScope)))->toBe('created');

    $result = createDelegationOrFailure(
        $delegator,
        $sameDelegate ? $delegate : $otherDelegate,
        $resolve($secondScope),
    );

    expect($result)->toBe($expected);
})->with([
    'operações disjuntas convivem' => [
        ['scope_type' => 'operation', 'operation' => 'X'],
        ['scope_type' => 'operation', 'operation' => 'Y'],
        true,
        'created',
    ],
    'etapas disjuntas convivem' => [
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        ['scope_type' => 'stage', 'scope_stage' => 2, 'scope_responsibility' => 'management_reviewer'],
        true,
        'created',
    ],
    'responsabilidades disjuntas na etapa cinco convivem' => [
        ['scope_type' => 'stage', 'scope_stage' => 5, 'scope_responsibility' => 'receipt_uploader'],
        ['scope_type' => 'stage', 'scope_stage' => 5, 'scope_responsibility' => 'finalizer'],
        true,
        'created',
    ],
    'mesma operação para o mesmo delegado conflita' => [
        ['scope_type' => 'operation', 'operation' => 'X'],
        ['scope_type' => 'operation', 'operation' => 'X'],
        true,
        'conflict',
    ],
    'mesma etapa para o mesmo delegado conflita' => [
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        true,
        'conflict',
    ],
    'mesma operação para outro delegado conflita' => [
        ['scope_type' => 'operation', 'operation' => 'X'],
        ['scope_type' => 'operation', 'operation' => 'X'],
        false,
        'conflict',
    ],
    'mesma etapa para outro delegado conflita' => [
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        false,
        'conflict',
    ],
    'global depois de operação conflita como redundância' => [
        ['scope_type' => 'operation', 'operation' => 'X'],
        ['scope_type' => 'global'],
        true,
        'conflict',
    ],
    'etapa sem operação abrange a operação já delegada' => [
        ['scope_type' => 'operation', 'operation' => 'X'],
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        true,
        'conflict',
    ],
]);

/**
 * Antes da P2.3 as quatro linhas abaixo terminavam em `cycle`: bastava existir
 * um caminho A → B → A no grafo para a reciprocidade ser recusada, sem olhar o
 * escopo. Só a primeira é reciprocidade de verdade -- as outras três eram falso
 * positivo, e é essa diferença que este caso fixa.
 */
it('classifica reciprocidade pelo contexto e não pela existência do caminho', function (
    array $forwardScope,
    array $backwardScope,
    string $expected,
) {
    $a = delegationParticipant();
    $b = delegationParticipant();
    // Ambos com responsabilidade direta própria, em operações distintas.
    $operationA = Operation::factory()->create(array_fill_keys(operationResponsibilityColumns(), $a->getKey()));
    $operationB = Operation::factory()->create(array_fill_keys(operationResponsibilityColumns(), $b->getKey()));

    $forwardScope = array_merge($forwardScope, isset($forwardScope['needsOperation'])
        ? ['scope_operation_id' => $operationA->getKey()]
        : []);
    $backwardScope = array_merge($backwardScope, isset($backwardScope['needsOperation'])
        ? ['scope_operation_id' => $operationB->getKey()]
        : []);
    unset($forwardScope['needsOperation'], $backwardScope['needsOperation']);

    expect(createDelegationOrFailure($a, $b, $forwardScope))->toBe('created')
        ->and(createDelegationOrFailure($b, $a, $backwardScope))->toBe($expected);
})->with([
    'global e global' => [['scope_type' => 'global'], ['scope_type' => 'global'], 'cycle'],
    'operações disjuntas' => [
        ['scope_type' => 'operation', 'needsOperation' => true],
        ['scope_type' => 'operation', 'needsOperation' => true],
        'created',
    ],
    'etapas disjuntas' => [
        ['scope_type' => 'stage', 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        ['scope_type' => 'stage', 'scope_stage' => 2, 'scope_responsibility' => 'management_reviewer'],
        'created',
    ],
    'responsabilidades disjuntas na etapa cinco' => [
        ['scope_type' => 'stage', 'scope_stage' => 5, 'scope_responsibility' => 'receipt_uploader'],
        ['scope_type' => 'stage', 'scope_stage' => 5, 'scope_responsibility' => 'finalizer'],
        'created',
    ],
]);

it('permite delegar autoridade direta própria e recusa quando o assignment direto some', function () {
    $responsibility = MeasurementResponsibility::EngineeringReviewer;
    $a = delegationParticipant();
    $b = delegationParticipant();
    $c = delegationParticipant();

    // B recebe autoridade de A numa operação, e tem autoridade DIRETA em outra.
    $operationOfA = operationHeldOnlyFor($a, $responsibility);
    $operationOfB = operationHeldOnlyFor($b, $responsibility);

    expect(createDelegationOrFailure($a, $b, [
        'scope_type' => 'operation',
        'scope_operation_id' => $operationOfA->getKey(),
    ]))->toBe('created');

    // Redelegar a operação recebida de A é bloqueado.
    expect(createDelegationOrFailure($b, $c, [
        'scope_type' => 'operation',
        'scope_operation_id' => $operationOfA->getKey(),
    ]))->toBe('conflict');

    // Delegar a própria operação direta segue permitido.
    expect(createDelegationOrFailure($b, $c, [
        'scope_type' => 'operation',
        'scope_operation_id' => $operationOfB->getKey(),
    ]))->toBe('created');

    // Removido o assignment direto de B, ele não pode mais delegar aquela operação.
    $operationOfB->forceFill([$responsibility->operationColumn() => null])->save();
    $d = delegationParticipant();

    expect(createDelegationOrFailure($b, $d, [
        'scope_type' => 'operation',
        'scope_operation_id' => $operationOfB->getKey(),
    ]))->toBe('conflict');
});

it('resolve a vigência da delegação pelos limites exatos do intervalo', function () {
    $responsibility = MeasurementResponsibility::EngineeringReviewer;
    $delegator = delegationParticipant();
    $delegate = delegationParticipant();
    $operation = operationHeldOnlyFor($delegator, $responsibility);
    $startsAt = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');
    $endsAt = CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC');

    ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'revoked_at' => null,
    ]);

    $service = app(ResponsibilityDelegationService::class);

    $cases = [
        'antes do início' => [$startsAt->subSecond(), false],
        'exatamente no início' => [$startsAt, true],
        'durante' => [$startsAt->addDay(), true],
        'exatamente no fim' => [$endsAt, true],
        'depois do fim' => [$endsAt->addSecond(), false],
    ];

    foreach ($cases as $label => [$instant, $expected]) {
        CarbonImmutable::setTestNow($instant);

        expect([$label => $service->hasAnyActiveDelegatedResponsibility($delegate->fresh(), $operation->fresh())])
            ->toBe([$label => $expected])
            ->and([$label => $service->activeDelegationFor($delegate->fresh(), $operation->fresh(), $responsibility) !== null])
            ->toBe([$label => $expected]);
    }
});
