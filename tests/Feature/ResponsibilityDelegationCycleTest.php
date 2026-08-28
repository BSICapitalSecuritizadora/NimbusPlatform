<?php

use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\ResponsibilityDelegationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function cycleParticipant(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'delegations.create', 'measurements.review', 'measurements.pay',
        'measurements.receipts', 'measurements.finalize',
    ]);

    return $user;
}

/** Operação em que o usuário detém todas as responsabilidades, para que possa delegar qualquer escopo. */
function cycleOperationOf(User $holder): Operation
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
 * @param  array<string, mixed>  $scope
 * @return 'created'|'cycle'|'conflict'
 */
function cycleAttempt(
    User $delegator,
    User $delegate,
    array $scope,
    mixed $startsAt = null,
    mixed $endsAt = null,
): string {
    try {
        app(ResponsibilityDelegationService::class)->createDelegation(array_merge([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
            'starts_at' => $startsAt ?? now()->subHour(),
            'ends_at' => $endsAt ?? now()->addDays(5),
            'reason' => 'Cobertura de férias',
        ], $scope), $delegator);

        return 'created';
    } catch (ValidationException $exception) {
        $message = (string) collect($exception->errors())->flatten()->first();

        return str_contains($message, 'Ciclo') ? 'cycle' : 'conflict';
    }
}

/**
 * A condição que a regra anterior bastava para recusar: existe aresta não
 * revogada de volta ao delegante com `ends_at >= now()`, sem olhar escopo nem
 * período. Toda linha que hoje resulta em `created` e ainda satisfaz isto era
 * falso positivo da regra antiga -- é o que prova que estes casos capturam o
 * comportamento incorreto e não apenas o novo.
 */
function formerlyBlockedReciprocity(User $delegator, User $delegate): bool
{
    return ResponsibilityDelegation::query()
        ->whereNull('revoked_at')
        ->where('ends_at', '>=', now())
        ->where('delegator_user_id', $delegate->getKey())
        ->where('delegate_user_id', $delegator->getKey())
        ->exists();
}

it('bloqueia reciprocidade apenas quando os contextos funcionais se intersectam', function (
    string $case,
    string $expected,
) {
    $a = cycleParticipant();
    $b = cycleParticipant();
    $operationOfA = cycleOperationOf($a);
    $operationOfB = cycleOperationOf($b);
    // Operação compartilhada: A responde pela engenharia, B pelo pagamento.
    $shared = Operation::factory()->create([
        'responsible_user_id' => $a->getKey(),
        'payment_manager_user_id' => $b->getKey(),
    ]);

    [$forward, $backward] = match ($case) {
        'global' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL],
            ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL],
        ],
        'mesma operação' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_OPERATION, 'scope_operation_id' => $shared->getKey()],
            ['scope_type' => ResponsibilityDelegation::SCOPE_OPERATION, 'scope_operation_id' => $shared->getKey()],
        ],
        'operações distintas' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_OPERATION, 'scope_operation_id' => $operationOfA->getKey()],
            ['scope_type' => ResponsibilityDelegation::SCOPE_OPERATION, 'scope_operation_id' => $operationOfB->getKey()],
        ],
        'mesma etapa' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
        ],
        'etapas distintas' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer'],
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 2, 'scope_responsibility' => 'management_reviewer'],
        ],
        'mesma responsabilidade na etapa cinco' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 5, 'scope_responsibility' => 'receipt_uploader'],
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 5, 'scope_responsibility' => 'receipt_uploader'],
        ],
        'responsabilidades distintas na etapa cinco' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 5, 'scope_responsibility' => 'receipt_uploader'],
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 5, 'scope_responsibility' => 'finalizer'],
        ],
        'operação contra etapa abrangente' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_OPERATION, 'scope_operation_id' => $shared->getKey()],
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 4, 'scope_responsibility' => 'payment_manager'],
        ],
        'operação contra etapa de outra operação' => [
            ['scope_type' => ResponsibilityDelegation::SCOPE_OPERATION, 'scope_operation_id' => $operationOfA->getKey()],
            ['scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 1, 'scope_responsibility' => 'engineering_reviewer', 'scope_operation_id' => $operationOfB->getKey()],
        ],
    };

    expect(cycleAttempt($a, $b, $forward))->toBe('created')
        ->and(cycleAttempt($b, $a, $backward))->toBe($expected);

    if ($expected === 'created') {
        expect(formerlyBlockedReciprocity($b, $a))->toBeTrue();
    }
})->with([
    ['global', 'cycle'],
    ['mesma operação', 'cycle'],
    ['operações distintas', 'created'],
    ['mesma etapa', 'cycle'],
    ['etapas distintas', 'created'],
    ['mesma responsabilidade na etapa cinco', 'cycle'],
    ['responsabilidades distintas na etapa cinco', 'created'],
    ['operação contra etapa abrangente', 'cycle'],
    ['operação contra etapa de outra operação', 'created'],
]);

it('bloqueia ciclo transitivo de três usuários no mesmo contexto', function () {
    [$a, $b, $c] = [cycleParticipant(), cycleParticipant(), cycleParticipant()];
    cycleOperationOf($a);
    cycleOperationOf($b);
    $operationOfC = cycleOperationOf($c);

    expect(cycleAttempt($a, $b, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($b, $c, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($c, $a, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operationOfC->getKey(),
        ]))->toBe('cycle');
});

it('bloqueia ciclo transitivo de quatro usuários no mesmo contexto', function () {
    $users = collect(range(1, 4))->map(fn (): User => cycleParticipant())->all();

    foreach ($users as $user) {
        cycleOperationOf($user);
    }

    expect(cycleAttempt($users[0], $users[1], ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($users[1], $users[2], ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($users[2], $users[3], ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($users[3], $users[0], ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('cycle');
});

it('não classifica como ciclo a cadeia transitiva cortada por um escopo disjunto', function () {
    [$a, $b, $c] = [cycleParticipant(), cycleParticipant(), cycleParticipant()];
    cycleOperationOf($a);
    $operationOfB = cycleOperationOf($b);
    $operationOfC = cycleOperationOf($c);

    // A → B abrange tudo, mas B → C só alcança a operação de B, que não é a de C.
    expect(cycleAttempt($a, $b, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($b, $c, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operationOfB->getKey(),
        ]))->toBe('created')
        ->and(cycleAttempt($c, $a, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operationOfC->getKey(),
        ]))->toBe('created');
});

it('bloqueia a mesma cadeia transitiva quando o trecho intermediário volta a intersectar', function () {
    [$a, $b, $c] = [cycleParticipant(), cycleParticipant(), cycleParticipant()];
    cycleOperationOf($a);
    cycleOperationOf($b);
    // A operação pela qual C fecha a volta é a mesma que B repassou adiante.
    $shared = Operation::factory()->create(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $b->getKey(),
    ));
    $shared->forceFill(['payment_manager_user_id' => $c->getKey()])->save();

    expect(cycleAttempt($a, $b, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created')
        ->and(cycleAttempt($b, $c, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $shared->getKey(),
        ]))->toBe('created')
        ->and(cycleAttempt($c, $a, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $shared->getKey(),
        ]))->toBe('cycle');
});

it('só bloqueia reciprocidade quando as vigências podem coexistir', function (
    string $existingStartsAt,
    string $existingEndsAt,
    string $newStartsAt,
    string $newEndsAt,
    string $expected,
) {
    $a = cycleParticipant();
    $b = cycleParticipant();
    cycleOperationOf($a);
    cycleOperationOf($b);

    expect(cycleAttempt(
        $b,
        $a,
        ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL],
        $existingStartsAt,
        $existingEndsAt,
    ))->toBe('created');

    expect(cycleAttempt(
        $a,
        $b,
        ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL],
        $newStartsAt,
        $newEndsAt,
    ))->toBe($expected);

    if ($expected === 'created') {
        expect(formerlyBlockedReciprocity($a, $b))->toBeTrue();
    }
})->with([
    'períodos sem interseção' => [
        '2026-09-01 12:00:00', '2026-09-10 12:00:00',
        '2026-09-20 12:00:00', '2026-09-30 12:00:00',
        'created',
    ],
    'interseção parcial' => [
        '2026-09-01 12:00:00', '2026-09-15 12:00:00',
        '2026-09-10 12:00:00', '2026-09-30 12:00:00',
        'cycle',
    ],
    'encosta exatamente no ends_at existente' => [
        '2026-09-05 12:00:00', '2026-09-10 12:00:00',
        '2026-09-10 12:00:00', '2026-09-20 12:00:00',
        'cycle',
    ],
    'um segundo depois do ends_at existente' => [
        '2026-09-05 12:00:00', '2026-09-10 12:00:00',
        '2026-09-10 12:00:01', '2026-09-20 12:00:00',
        'created',
    ],
    'encosta exatamente no starts_at existente' => [
        '2026-09-05 12:00:00', '2026-09-10 12:00:00',
        '2026-09-01 12:00:00', '2026-09-05 12:00:00',
        'cycle',
    ],
    'um segundo antes do starts_at existente' => [
        '2026-09-05 12:00:00', '2026-09-10 12:00:00',
        '2026-09-01 12:00:00', '2026-09-05 11:59:59',
        'created',
    ],
    'aresta futura com interseção futura real' => [
        '2026-09-20 12:00:00', '2026-09-30 12:00:00',
        '2026-09-01 12:00:00', '2026-09-25 12:00:00',
        'cycle',
    ],
    'aresta futura sem interseção temporal' => [
        '2026-09-20 12:00:00', '2026-09-30 12:00:00',
        '2026-09-01 12:00:00', '2026-09-19 12:00:00',
        'created',
    ],
]);

it('não considera aresta revogada nem aresta expirada no ciclo', function (string $condition) {
    $a = cycleParticipant();
    $b = cycleParticipant();
    cycleOperationOf($a);

    $state = match ($condition) {
        'revogada' => [
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
            'revoked_at' => now(),
            'revoked_by' => $b->getKey(),
        ],
        'expirada' => [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
            'revoked_at' => null,
        ],
    };

    ResponsibilityDelegation::factory()->create(array_merge([
        'delegator_user_id' => $b->getKey(),
        'delegate_user_id' => $a->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
    ], $state));

    expect(cycleAttempt($a, $b, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created');
})->with(['revogada', 'expirada']);

it('não deixa a aresta expirada bloquear apenas por sobrepor o passado da nova delegação', function () {
    $a = cycleParticipant();
    $b = cycleParticipant();
    cycleOperationOf($a);

    // B → A já terminou; a nova A → B começa antes desse fim, mas a reciprocidade
    // só poderia ter existido num intervalo que já passou.
    ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $b->getKey(),
        'delegate_user_id' => $a->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
        'revoked_at' => null,
    ]);

    expect(cycleAttempt(
        $a,
        $b,
        ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL],
        now()->subDays(5),
        now()->addDays(5),
    ))->toBe('created');
});

it('mantém a redelegação proibida mesmo com a detecção de ciclo mais precisa', function () {
    $a = cycleParticipant();
    $b = cycleParticipant();
    $c = cycleParticipant();
    $operationOfA = cycleOperationOf($a);
    $operationOfB = cycleOperationOf($b);

    // B recebe autoridade de A sobre a operação de A.
    expect(cycleAttempt($a, $b, [
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operationOfA->getKey(),
    ]))->toBe('created')
        // Autoridade recebida não é redelegável: B não pode repassar a operação de A.
        ->and(cycleAttempt($b, $c, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operationOfA->getKey(),
        ]))->toBe('conflict')
        // A autoridade própria de B segue delegável.
        ->and(cycleAttempt($b, $c, [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operationOfB->getKey(),
        ]))->toBe('created');

    // Removido o assignment direto, a autoridade própria de B deixa de existir.
    $operationOfB->forceFill(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        null,
    ))->save();

    expect(cycleAttempt($b, cycleParticipant(), [
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operationOfB->getKey(),
    ]))->toBe('conflict');
});

it('percorre o grafo com uma consulta por nível, sem N+1 por aresta', function () {
    $a = cycleParticipant();
    $b = cycleParticipant();
    $x = cycleParticipant();
    cycleOperationOf($a);
    cycleOperationOf($x);
    $branches = collect(range(1, 3))->map(fn (): Operation => cycleOperationOf($b));

    expect(cycleAttempt($a, $b, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created');

    // Três arestas saindo de B, cada uma para um delegado distinto, em operações disjuntas.
    foreach ($branches as $operation) {
        expect(cycleAttempt($b, cycleParticipant(), [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operation->getKey(),
        ]))->toBe('created');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(cycleAttempt($x, $a, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]))->toBe('created');

    $traversals = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains(
            $entry['query'],
            'select "id", "delegator_user_id", "delegate_user_id", "scope_type"',
        ))
        ->count();

    DB::disableQueryLog();

    // Um nível para A, um para B e um para os três delegados de B — juntos, não um por aresta.
    expect($traversals)->toBe(3);
});

/** A forma de query que só a travessia da detecção de ciclo emite. */
function cycleTraversalsWhileCreating(User $delegator, User $delegate): int
{
    $shape = 'select "id", "delegator_user_id", "delegate_user_id", "scope_type"';

    DB::flushQueryLog();
    DB::enableQueryLog();

    cycleAttempt($delegator, $delegate, ['scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL]);

    $traversals = count(array_filter(
        DB::getQueryLog(),
        fn (array $entry): bool => str_contains((string) $entry['query'], $shape),
    ));

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $traversals;
}

/** @param list<array{0: User, 1: User}> $edges */
function cycleInsertEdges(array $edges): void
{
    foreach ($edges as [$from, $to]) {
        ResponsibilityDelegation::factory()->create([
            'delegator_user_id' => $from->getKey(),
            'delegate_user_id' => $to->getKey(),
            'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(20),
            'revoked_at' => null,
        ]);
    }
}

it('faz o número de travessias acompanhar a profundidade da cadeia', function () {
    $root = cycleParticipant();
    cycleOperationOf($root);

    $chain = [cycleParticipant()];
    $edges = [];

    for ($level = 0; $level < 4; $level++) {
        $chain[] = cycleParticipant();
        $edges[] = [$chain[$level], $chain[$level + 1]];
    }

    cycleInsertEdges($edges);

    // Um nível para o delegado, um por elo, e um último que volta vazio.
    expect(cycleTraversalsWhileCreating($root, $chain[0]))->toBe(5);
});

it('não faz o número de travessias crescer com a largura do grafo', function () {
    $root = cycleParticipant();
    cycleOperationOf($root);

    $entry = cycleParticipant();
    $edges = [];

    // Vinte arestas saindo do mesmo delegado: um nível só, por mais largo que seja.
    for ($branch = 0; $branch < 20; $branch++) {
        $edges[] = [$entry, cycleParticipant()];
    }

    cycleInsertEdges($edges);

    expect(cycleTraversalsWhileCreating($root, $entry))->toBe(2);
});
