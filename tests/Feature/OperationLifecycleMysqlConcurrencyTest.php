<?php

use App\Enums\OperationStatus;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Services\OperationLifecycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar lockForUpdate() com processos concorrentes reais.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeOperationLifecycleActor(): User
{
    $actor = User::factory()->withTwoFactor()->create();
    $actor->assignRole('admin');

    return $actor;
}

/**
 * Uma das duas metades do portão, em processo próprio.
 *
 * O marcador em arquivo é a barreira determinística: o processo que trava a
 * operação primeiro escreve o arquivo e segura o lock; o outro só começa depois
 * de ver o arquivo. Nenhum `sleep` decide quem chega primeiro -- a espera é por
 * um fato observável, e o vencedor é sempre o mesmo.
 *
 * @param  array{action: string, operation_id: int, actor_id: int, lock_marker?: string, wait_for_marker?: string, hold_after_operation_lock_ms?: int}  $instruction
 */
function operationLifecycleTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['lock_marker'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                $sql = strtolower($query->sql);

                if (! str_contains($sql, 'operations') || ! str_contains($sql, 'for update')) {
                    return;
                }

                file_put_contents($instruction['lock_marker'], 'locked');
                usleep(((int) ($instruction['hold_after_operation_lock_ms'] ?? 0)) * 1000);
            });
        }

        $actor = User::query()->findOrFail($instruction['actor_id']);
        Notification::fake();

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou a aquisição do lock da Operation.');
                }
            }

            match ($instruction['action']) {
                'create_measurement' => DB::transaction(function () use ($instruction, $actor): void {
                    app(OperationLifecycleService::class)->lockForNewMeasurement(
                        $instruction['operation_id'],
                        $actor,
                    );

                    Measurement::query()->create([
                        'operation_id' => $instruction['operation_id'],
                        'reference_month' => '2026-09-01',
                        'filename' => 'concorrencia.pdf',
                        'storage_path' => 'nimbus_docs/measurements/concorrencia.pdf',
                        'status' => 'pending',
                        'current_stage' => 1,
                        'uploaded_by' => $actor->getKey(),
                        'uploaded_at' => now(),
                    ]);
                }, 3),
                'complete' => app(OperationLifecycleService::class)->complete(
                    Operation::query()->findOrFail($instruction['operation_id']),
                    $actor,
                ),
                'cancel' => app(OperationLifecycleService::class)->cancel(
                    Operation::query()->findOrFail($instruction['operation_id']),
                    $actor,
                    'Cancelamento concorrente.',
                ),
            };

            return ['success' => true, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class];
        }
    };
}

/**
 * @return array{actor: User, operation: Operation}
 */
function createOperationLifecycleRaceScenario(): array
{
    $actor = makeOperationLifecycleActor();

    return [
        'actor' => $actor,
        'operation' => Operation::factory()->create([
            'status' => OperationStatus::Active,
            'assigned_user_id' => $actor->getKey(),
            'responsible_user_id' => $actor->getKey(),
        ]),
    ];
}

it('never lets a measurement be born into an operation that is being completed', function () {
    $scenario = createOperationLifecycleRaceScenario();
    $marker = temporaryTestFilePath('op-lifecycle-complete-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        operationLifecycleTask([
            'action' => 'create_measurement',
            'operation_id' => $scenario['operation']->getKey(),
            'actor_id' => $scenario['actor']->getKey(),
            'lock_marker' => $marker,
            'hold_after_operation_lock_ms' => 400,
        ]),
        operationLifecycleTask([
            'action' => 'complete',
            'operation_id' => $scenario['operation']->getKey(),
            'actor_id' => $scenario['actor']->getKey(),
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $operation = $scenario['operation']->fresh();
    $measurements = Measurement::query()->where('operation_id', $operation->getKey())->get();

    // Só um dos dois vence, e o resultado é sempre coerente: ou a medição
    // nasceu e a operação continua em andamento, ou a operação encerrou e
    // nenhuma medição nasceu. O estado proibido -- terminal com medição aberta
    // -- não é alcançável por nenhuma das duas ordens.
    expect(collect($results)->where('success', true))->toHaveCount(1);

    if ($operation->status->isTerminal()) {
        expect($measurements)->toHaveCount(0);

        return;
    }

    expect($operation->status)->toBe(OperationStatus::Active)
        ->and($measurements)->toHaveCount(1)
        ->and($measurements->first()->isOpen())->toBeTrue();
})->group('mysql');

it('never lets a measurement be born into an operation that is being canceled', function () {
    $scenario = createOperationLifecycleRaceScenario();
    $marker = temporaryTestFilePath('op-lifecycle-cancel-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        operationLifecycleTask([
            'action' => 'cancel',
            'operation_id' => $scenario['operation']->getKey(),
            'actor_id' => $scenario['actor']->getKey(),
            'lock_marker' => $marker,
            'hold_after_operation_lock_ms' => 400,
        ]),
        operationLifecycleTask([
            'action' => 'create_measurement',
            'operation_id' => $scenario['operation']->getKey(),
            'actor_id' => $scenario['actor']->getKey(),
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $operation = $scenario['operation']->fresh();
    $measurements = Measurement::query()->where('operation_id', $operation->getKey())->get();

    expect(collect($results)->where('success', true))->toHaveCount(1);

    if ($operation->status === OperationStatus::Canceled) {
        expect($measurements)->toHaveCount(0);

        return;
    }

    expect($operation->status)->toBe(OperationStatus::Active)
        ->and($measurements)->toHaveCount(1);
})->group('mysql');

it('serializes two competing closures of the same operation', function () {
    $scenario = createOperationLifecycleRaceScenario();
    $base = [
        'operation_id' => $scenario['operation']->getKey(),
        'actor_id' => $scenario['actor']->getKey(),
    ];

    $results = Concurrency::driver('process')->run([
        operationLifecycleTask(['action' => 'complete'] + $base),
        operationLifecycleTask(['action' => 'cancel'] + $base),
    ]);

    // O segundo encontra a operação já terminal e é recusado pela tabela de
    // transições: completed e canceled não conversam entre si.
    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and($scenario['operation']->fresh()->status->isTerminal())->toBeTrue();
})->group('mysql');
