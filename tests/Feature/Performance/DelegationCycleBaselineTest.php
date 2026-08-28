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
use Tests\Support\PerformanceProbe;

uses(RefreshDatabase::class);

/** A forma de query que só a travessia da detecção de ciclo emite. */
const BFS_QUERY_SHAPE = 'select "id", "delegator_user_id", "delegate_user_id", "scope_type"';

beforeEach(function () {
    if (env('NIMBUS_BENCH') !== '1') {
        test()->markTestSkipped('Benchmark da P2.4: defina NIMBUS_BENCH=1 para executar.');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return list<User> */
function bfsUsers(int $count): array
{
    $users = [];

    for ($index = 0; $index < $count; $index++) {
        $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
        $user->givePermissionTo([
            'delegations.create', 'measurements.review', 'measurements.pay',
            'measurements.receipts', 'measurements.finalize',
        ]);
        $users[] = $user;
    }

    return $users;
}

function bfsOperationOf(User $holder): Operation
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
 * Insere arestas direto na tabela: são o grafo pré-existente, não o objeto da
 * medição, e passar pelo serviço as submeteria às regras de overlap.
 *
 * @param  list<array{from: User, to: User, scope?: string, operation?: ?int, stage?: ?int, responsibility?: ?string, starts?: string, ends?: string}>  $edges
 */
function bfsInsertEdges(array $edges): void
{
    $rows = [];

    foreach ($edges as $edge) {
        $rows[] = [
            'delegator_user_id' => $edge['from']->getKey(),
            'delegate_user_id' => $edge['to']->getKey(),
            'scope_type' => $edge['scope'] ?? ResponsibilityDelegation::SCOPE_GLOBAL,
            'scope_operation_id' => $edge['operation'] ?? null,
            'scope_stage' => $edge['stage'] ?? null,
            'scope_responsibility' => $edge['responsibility'] ?? null,
            'starts_at' => $edge['starts'] ?? now()->subDay(),
            'ends_at' => $edge['ends'] ?? now()->addDays(30),
            'reason' => 'benchmark',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('responsibility_delegations')->insert($chunk);
    }
}

/**
 * A MEASURED SECTION: uma tentativa de criação, desfeita por rollback para que
 * as repetições midam sempre o mesmo grafo.
 *
 * @param  array<string, mixed>  $scope
 * @return callable():string
 */
function bfsAttempt(User $delegator, User $delegate, array $scope = []): callable
{
    return function () use ($delegator, $delegate, $scope): string {
        DB::beginTransaction();

        try {
            app(ResponsibilityDelegationService::class)->createDelegation(array_merge([
                'delegator_user_id' => $delegator->getKey(),
                'delegate_user_id' => $delegate->getKey(),
                'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(20),
                'reason' => 'benchmark',
            ], $scope), $delegator);

            return 'created';
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();

            return str_contains($message, 'Ciclo') ? 'cycle' : 'conflict';
        } finally {
            DB::rollBack();
        }
    };
}

function bfsReport(PerformanceProbe $probe, string $label, int $edges): void
{
    fwrite(STDERR, PHP_EOL.$probe->summary($label).PHP_EOL);
    fwrite(STDERR, sprintf(
        '   arestas=%-5d travessias=%-4d linhas_lidas=%-5d resultado=%s',
        $edges,
        $probe->countMatching(BFS_QUERY_SHAPE),
        $probe->rowsLoaded(BFS_QUERY_SHAPE),
        (string) $probe->result,
    ).PHP_EOL);
}

it('mede a BFS por profundidade da cadeia', function (int $depth) {
    $users = bfsUsers($depth + 2);
    $root = $users[0];
    bfsOperationOf($root);

    $edges = [];

    for ($level = 1; $level <= $depth; $level++) {
        $edges[] = ['from' => $users[$level], 'to' => $users[$level + 1]];
    }

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $users[1]), runs: 5);

    bfsReport($probe, "bfs/profundidade-{$depth}", count($edges));

    expect($probe->result)->toBe('created');
})->with([2, 5, 10, 20]);

it('mede a BFS por largura em cadeias paralelas', function (int $width) {
    $depth = 5;
    $users = bfsUsers($width * $depth + 2);
    $root = $users[0];
    $entry = $users[1];
    bfsOperationOf($root);

    $edges = [];
    $previous = [$entry];
    $cursor = 2;

    for ($level = 0; $level < $depth; $level++) {
        $current = [];

        for ($branch = 0; $branch < $width; $branch++) {
            $current[] = $users[$cursor++];
        }

        foreach ($previous as $index => $node) {
            foreach ($current as $branchIndex => $target) {
                // Cadeias paralelas: cada nó segue um ramo, sem produto cartesiano.
                if ($index === 0 || $index === $branchIndex) {
                    $edges[] = ['from' => $node, 'to' => $target];
                }
            }
        }

        $previous = $current;
    }

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $entry), runs: 5);

    bfsReport($probe, "bfs/largura-{$width}", count($edges));

    expect($probe->result)->toBe('created');
})->with([1, 5, 10, 25]);

it('mede a BFS quando o ciclo fecha em profundidades diferentes', function (string $case, int $depth) {
    $users = bfsUsers($depth + 2);
    $root = $users[0];
    bfsOperationOf($root);

    $edges = [];

    for ($level = 1; $level <= $depth; $level++) {
        $edges[] = ['from' => $users[$level], 'to' => $users[$level + 1]];
    }

    // A volta ao delegante original fecha o ciclo no último nível.
    $edges[] = ['from' => $users[$depth + 1], 'to' => $root];

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $users[1]), runs: 5);

    bfsReport($probe, "bfs/{$case}", count($edges));

    expect($probe->result)->toBe('cycle');
})->with([
    ['ciclo-raso', 1],
    ['ciclo-profundo', 10],
]);

it('mede o corte precoce por escopo e por janela disjuntos', function (string $case) {
    $depth = 10;
    $users = bfsUsers($depth + 2);
    $root = $users[0];
    $entry = $users[1];
    $operationOfRoot = bfsOperationOf($root);
    $otherOperation = bfsOperationOf($users[$depth + 1]);

    $edges = [];

    for ($level = 1; $level <= $depth; $level++) {
        $edge = ['from' => $users[$level], 'to' => $users[$level + 1]];

        // O primeiro salto já não intersecta: a busca deve morrer ali.
        if ($level === 1 && $case === 'escopo-disjunto') {
            $edge['scope'] = ResponsibilityDelegation::SCOPE_OPERATION;
            $edge['operation'] = $otherOperation->getKey();
        }

        if ($level === 1 && $case === 'janela-disjunta') {
            $edge['starts'] = '2026-11-01 00:00:00';
            $edge['ends'] = '2026-11-30 00:00:00';
        }

        $edges[] = $edge;
    }

    $edges[] = ['from' => $users[$depth + 1], 'to' => $root];

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $entry, [
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operationOfRoot->getKey(),
    ]), runs: 5);

    bfsReport($probe, "bfs/{$case}", count($edges));

    expect($probe->result)->toBe('created');
})->with(['escopo-disjunto', 'janela-disjunta']);

it('mede a BFS com um leque de arestas paralelas saindo do mesmo delegante', function (int $fanOut) {
    $users = bfsUsers($fanOut + 2);
    $root = $users[0];
    $entry = $users[1];
    bfsOperationOf($root);

    $edges = [];

    for ($branch = 0; $branch < $fanOut; $branch++) {
        $edges[] = ['from' => $entry, 'to' => $users[$branch + 2]];
    }

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $entry), runs: 5);

    bfsReport($probe, "bfs/leque-{$fanOut}", count($edges));

    expect($probe->result)->toBe('created');
})->with([5, 25]);

it('mede a BFS num grafo com global, operação e etapa misturados', function () {
    $depth = 12;
    $users = bfsUsers($depth + 2);
    $root = $users[0];
    $entry = $users[1];
    bfsOperationOf($root);
    $shared = bfsOperationOf($users[2]);

    $edges = [];

    for ($level = 1; $level <= $depth; $level++) {
        $edge = ['from' => $users[$level], 'to' => $users[$level + 1]];

        $edges[] = match ($level % 3) {
            0 => $edge + [
                'scope' => ResponsibilityDelegation::SCOPE_STAGE,
                'stage' => 1,
                'responsibility' => 'engineering_reviewer',
            ],
            1 => $edge + [
                'scope' => ResponsibilityDelegation::SCOPE_OPERATION,
                'operation' => $shared->getKey(),
            ],
            default => $edge,
        };
    }

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $entry), runs: 5);

    bfsReport($probe, 'bfs/escopos-misturados', count($edges));

    expect($probe->result)->toBe('created');
});

it('audita a explosão de estados quando o mesmo nó é alcançado por contextos diferentes', function (int $paths) {
    $users = bfsUsers($paths + 3);
    $root = $users[0];
    $entry = $users[1];
    $hub = $users[2];
    bfsOperationOf($root);

    $edges = [];

    // `entry` alcança `hub` por N caminhos, cada um restrito a uma operação
    // diferente: N contextos distintos convergindo no mesmo nó.
    for ($path = 0; $path < $paths; $path++) {
        $intermediate = $users[$path + 3];
        $operation = bfsOperationOf($intermediate);

        $edges[] = [
            'from' => $entry,
            'to' => $intermediate,
            'scope' => ResponsibilityDelegation::SCOPE_OPERATION,
            'operation' => $operation->getKey(),
        ];
        $edges[] = ['from' => $intermediate, 'to' => $hub];
    }

    bfsInsertEdges($edges);

    $probe = PerformanceProbe::measure(bfsAttempt($root, $entry), runs: 5);

    bfsReport($probe, "bfs/contextos-convergentes-{$paths}", count($edges));

    expect($probe->result)->toBe('created');
})->with([5, 25]);
