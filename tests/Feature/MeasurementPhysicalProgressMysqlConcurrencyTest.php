<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

/*
 * O teto de 100% e o acumulado dependem de medições aprovadas em paralelo. A
 * aprovação da Engenharia trava a Operation como primeira instrução da
 * transação, com o `operation_id` já carregado; no MySQL em REPEATABLE READ a
 * fotografia da transação nasce na primeira leitura comum, e nascendo depois
 * desse lock ela enxerga a aprovação concorrente que acabou de commitar. Uma
 * leitura comum antes do lock (como a de `value('operation_id')`, que existia)
 * faria a segunda aprovação somar a partir do estado anterior à primeira --
 * cada corrida abaixo falha com ela. O SQLite serializa escritores e nunca
 * mostra isso.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar locks concorrentes. Execute: ./vendor/bin/sail composer test:measurements:mysql');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de composer test:measurements:mysql.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Ação do fluxo num processo próprio. Quem recebe `lock_marker` segura o
 * primeiro lock que pedir na tabela `lock_table` -- por 750 ms, ou, com
 * `hold_until_marker`, até esse arquivo aparecer; quem recebe `wait_for_marker`
 * só começa depois disso. Quem recebe `read_marker` o cria assim que lê as
 * fontes do progresso físico. `lock_wait_ms` devolve quanto a própria
 * transação esperou pelo lock da Operation.
 *
 * @param  array{action?: string, measurement_id: int, actor_id: int, plan_set_id: int, percent?: int|string, storage_root: string, lock_marker?: string, lock_table?: string, hold_until_marker?: string, wait_for_marker?: string, read_marker?: string, own_marker?: string, peer_marker?: string}  $instruction
 */
function physicalProgressApprovalTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();
        $lockWait = ['ms' => null];
        $barrier = ['met' => null];

        DB::listen(static function (QueryExecuted $query) use ($instruction, &$lockWait, &$barrier): void {
            static $marked = false;
            $sql = strtolower($query->sql);

            if ($lockWait['ms'] === null && str_contains($sql, '`operations`') && str_contains($sql, 'for update')) {
                $lockWait['ms'] = $query->time;

                // Barreira bilateral: cada um avisa que já tem o lock da própria
                // Operation e espera o outro -- só passa se os dois o tiverem juntos.
                if (isset($instruction['own_marker'], $instruction['peer_marker'])) {
                    file_put_contents($instruction['own_marker'], 'locked');
                    $deadline = microtime(true) + 5;

                    while (! is_file($instruction['peer_marker']) && microtime(true) < $deadline) {
                        usleep(10_000);
                    }

                    $barrier['met'] = is_file($instruction['peer_marker']);
                }
            }

            if (isset($instruction['read_marker']) && str_starts_with($sql, 'select')
                && str_contains($sql, '`engineering_snapshot`') && str_contains($sql, '`measurement_reviews`')) {
                file_put_contents($instruction['read_marker'], 'read');
            }

            if ($marked || ! isset($instruction['lock_marker'])
                || ! str_contains($sql, '`'.($instruction['lock_table'] ?? 'operations').'`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $marked = true;
            file_put_contents($instruction['lock_marker'], 'locked');

            if (! isset($instruction['hold_until_marker'])) {
                usleep(750_000);

                return;
            }

            $deadline = microtime(true) + 10;

            while (! is_file($instruction['hold_until_marker']) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! is_file($instruction['hold_until_marker'])) {
                throw new RuntimeException('O processo concorrente não leu as fontes enquanto o lock era mantido.');
            }
        });

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou o lock da Operation.');
                }
            }

            $measurement = Measurement::query()->findOrFail($instruction['measurement_id']);
            $actor = User::query()->findOrFail($instruction['actor_id']);

            if (($instruction['action'] ?? 'approve') === 'return_to_engineering') {
                app(MeasurementWorkflow::class)->reject($measurement, $actor, 'Devolução concorrente à Engenharia.', expectedStage: 2, expectedRevision: (int) $measurement->workflow_revision);
            } else {
                app(MeasurementWorkflow::class)->approve(
                    $measurement,
                    $actor,
                    engineeringProgress: [$instruction['plan_set_id'] => $instruction['percent']],
                    expectedStage: MeasurementWorkflow::STAGE_ENGINEERING,
                    expectedRevision: (int) $measurement->workflow_revision,
                );
            }

            return ['success' => true, 'exception' => null, 'message' => null, 'lock_wait_ms' => $lockWait['ms'], 'barrier_met' => $barrier['met']];
        } catch (ValidationException $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => implode(' ', $exception->errors()["realized.{$instruction['plan_set_id']}"] ?? []), 'lock_wait_ms' => $lockWait['ms'], 'barrier_met' => $barrier['met']];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage(), 'lock_wait_ms' => $lockWait['ms'], 'barrier_met' => $barrier['met']];
        }
    };
}

/**
 * @param  array{actor: User, planSet: MeasurementPlanSet}  $scenario
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function racePhysicalProgressApprovals(array $scenario, Measurement $first, int|string $firstPercent, Measurement $second, int|string $secondPercent): array
{
    $marker = temporaryTestFilePath('physical-progress-lock', 'lock');
    @unlink($marker);
    $base = [
        'actor_id' => $scenario['actor']->id,
        'plan_set_id' => $scenario['planSet']->id,
        'storage_root' => Storage::disk('local')->path(''),
    ];

    $results = Concurrency::driver('process')->run([
        physicalProgressApprovalTask(['measurement_id' => $first->id, 'percent' => $firstPercent, 'lock_marker' => $marker] + $base),
        physicalProgressApprovalTask(['measurement_id' => $second->id, 'percent' => $secondPercent, 'wait_for_marker' => $marker] + $base),
    ]);
    @unlink($marker);

    return $results;
}

it('lets only one of two concurrent approvals that together exceed 100% become effective on MySQL', function () {
    $scenario = Scenario::plan(initialPercent: '90.00');
    $may = Scenario::measurement($scenario, '2026-05');
    $june = Scenario::measurement($scenario, '2026-06');

    $results = racePhysicalProgressApprovals($scenario, $may, 8, $june, 7);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(ValidationException::class)
        ->and($results[1]['message'])->toContain('Progresso atual: 98,00%. Percentual informado: 7,00%. Máximo restante: 2,00%.')
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('98.00')
        ->and($june->fresh()->engineering_snapshot)->toBeNull()
        ->and($june->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($scenario['lines']['2026-06']->fresh()->measurement_id)->toBeNull();
})->group('mysql');

it('includes a month approved concurrently in the cumulative of the next month on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::measurement($scenario, '2026-05');
    $june = Scenario::measurement($scenario, '2026-06');

    $results = racePhysicalProgressApprovals($scenario, $may, 10, $june, 5);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeTrue()
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($scenario['lines']['2026-06']->fresh()->realized_cumulative_percent)->toBe('15.00')
        ->and($june->fresh()->engineering_snapshot['plan_sets'][0]['realized_cumulative_percent'])->toBe('15.00')
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('15.00');
})->group('mysql');

it('lets only one of two concurrent approvals claim the same schedule line on MySQL', function () {
    $scenario = Scenario::plan();
    $first = Scenario::measurement($scenario, '2026-05');
    $second = Scenario::measurement($scenario, '2026-05');

    $results = racePhysicalProgressApprovals($scenario, $first, 10, $second, 4);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(ValidationException::class)
        ->and($results[1]['message'])->toContain("já está vinculada à medição #{$first->id}, aprovada pela Engenharia.")
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($scenario['lines']['2026-05']->fresh()->measurement_id)->toBe($first->id)
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('10.00');
})->group('mysql');

it('approves Engineering in two operations at the same time without blocking each other on MySQL', function () {
    $first = Scenario::plan();
    $second = Scenario::plan();
    $firstMeasurement = Scenario::measurement($first, '2026-05');
    $secondMeasurement = Scenario::measurement($second, '2026-05');
    $root = Storage::disk('local')->path('');
    $firstMarker = temporaryTestFilePath('physical-progress-first-operation', 'lock');
    $secondMarker = temporaryTestFilePath('physical-progress-second-operation', 'lock');
    @unlink($firstMarker);
    @unlink($secondMarker);

    $results = Concurrency::driver('process')->run([
        physicalProgressApprovalTask(['measurement_id' => $firstMeasurement->id, 'actor_id' => $first['actor']->id, 'plan_set_id' => $first['planSet']->id, 'percent' => 10, 'storage_root' => $root, 'own_marker' => $firstMarker, 'peer_marker' => $secondMarker]),
        physicalProgressApprovalTask(['measurement_id' => $secondMeasurement->id, 'actor_id' => $second['actor']->id, 'plan_set_id' => $second['planSet']->id, 'percent' => 20, 'storage_root' => $root, 'own_marker' => $secondMarker, 'peer_marker' => $firstMarker]),
    ]);
    @unlink($firstMarker);
    @unlink($secondMarker);

    expect(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and(collect($results)->pluck('barrier_met')->all())->toBe([true, true])
        ->and(Scenario::progress($first)->currentPercent())->toBe('10.00')
        ->and(Scenario::progress($second)->currentPercent())->toBe('20.00');
})->group('mysql');

/**
 * A devolução à Engenharia trava só a medição, não a Operation: uma aprovação
 * simultânea pode ler o avanço de antes da devolução. A ordem aqui é fixa -- a
 * devolução só faz commit depois que a aprovação leu as fontes -- e prova que
 * essa leitura atrasada só superestima o atual: recusa a mais, nunca passa de
 * 100%. Na ordem inversa a aprovação lê 30% e é aceita, como no SQLite.
 */
it('decides from the approved state it read when a measurement returns to Engineering during an approval on MySQL', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::measured($scenario, '2026-05', 60);
    $june = Scenario::measurement($scenario, '2026-06');
    $marker = temporaryTestFilePath('physical-progress-return-lock', 'lock');
    $readMarker = temporaryTestFilePath('physical-progress-return-read', 'lock');
    @unlink($marker);
    @unlink($readMarker);
    $base = ['actor_id' => $scenario['actor']->id, 'plan_set_id' => $scenario['planSet']->id, 'storage_root' => Storage::disk('local')->path('')];

    $results = Concurrency::driver('process')->run([
        physicalProgressApprovalTask(['action' => 'return_to_engineering', 'measurement_id' => $may->id, 'lock_marker' => $marker, 'lock_table' => 'measurements', 'hold_until_marker' => $readMarker] + $base),
        physicalProgressApprovalTask(['measurement_id' => $june->id, 'percent' => 15, 'wait_for_marker' => $marker, 'read_marker' => $readMarker] + $base),
    ]);
    @unlink($marker);
    @unlink($readMarker);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['message'])->toContain('Progresso atual: 90,00%. Percentual informado: 15,00%. Máximo restante: 10,00%.')
        ->and($may->fresh()->engineering_snapshot)->toBeNull()
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('30.00');
})->group('mysql');

it('rolls the initial physical progress back dropping the CHECK before the columns on MySQL', function () {
    $migration = require database_path('migrations/2026_10_05_170828_add_initial_physical_progress_to_measurement_plan_sets_table.php');
    $hasRangeCheck = fn (): bool => DB::table('information_schema.TABLE_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TABLE_NAME', 'measurement_plan_sets')
        ->where('CONSTRAINT_NAME', 'measurement_plan_sets_initial_physical_progress_check')
        ->exists();
    Scenario::plan();

    expect($hasRangeCheck())->toBeTrue();

    $migration->down();

    expect($hasRangeCheck())->toBeFalse()
        ->and(Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_percent'))->toBeFalse()
        ->and(Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_reference_date'))->toBeFalse();

    $migration->up();

    expect($hasRangeCheck())->toBeTrue()
        ->and(Schema::hasColumns('measurement_plan_sets', ['initial_physical_progress_percent', 'initial_physical_progress_reference_date']))->toBeTrue();
})->group('mysql');

it('refuses an initial physical progress outside 0% to 100% in the database itself on MySQL', function (string $percent) {
    $scenario = Scenario::plan();

    expect(fn () => DB::table('measurement_plan_sets')
        ->where('id', $scenario['planSet']->id)
        ->update(['initial_physical_progress_percent' => $percent]))
        ->toThrow(QueryException::class);

    expect($scenario['planSet']->fresh()->initial_physical_progress_percent)->toBe('0.00');
})->with([
    'just above 100%' => ['100.01'],
    'negative' => ['-1.00'],
])->group('mysql');
