<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementLegacyInitialProgressFixture as Fixture;

/*
 * O relatório do avanço físico inicial no MySQL de verdade, onde o
 * RefreshDatabase não alcança: a migração real derrubada e refeita -- DDL faz
 * commit implícito -- para a previsão antes de as colunas existirem, e a
 * transação READ ONLY em que o próprio servidor recusa qualquer escrita, que só
 * pode ser aberta sem transação em curso. O banco é recriado por teste e as
 * linhas commitadas são varridas no fim.
 */
pest()->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para o DDL real da migração e a transação READ ONLY. Execute pelo scripts/parity-check.sh com este arquivo.');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de scripts/parity-check.sh.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

it('predicts on MySQL, before the columns exist, the decision the real migration records', function () {
    $hasRangeCheck = fn (): bool => DB::table('information_schema.TABLE_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TABLE_NAME', 'measurement_plan_sets')
        ->where('CONSTRAINT_NAME', 'measurement_plan_sets_initial_physical_progress_check')
        ->exists();
    $expected = [];

    foreach ([
        'proven on the first line',
        'approved before the engineering snapshot existed',
        'negative monthly progress summed',
        'not measured yet',
        'a later line approved from a zero base',
        'unreadable snapshot entry read as zero',
        'contradicted by the lowest measurement id even when reviewed later',
        'copy would take the plan just above 100%',
        'two plans in the same operation',
        'one approval before the snapshot on two plans of the same operation',
        'legacy initial outside 0% to 100%',
        'no legacy initial on the first line',
    ] as $scenario) {
        $expected += Fixture::scenario($scenario);
    }

    $migration = Fixture::migration();
    $migration->down();

    expect($hasRangeCheck())->toBeFalse()
        ->and(Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_percent'))->toBeFalse();

    ['exit_code' => $exitBefore, 'payload' => $before, 'plans' => $predicted] = Fixture::jsonReport();

    $migration->up();

    ['exit_code' => $exitAfter, 'payload' => $after, 'plans' => $recorded] = Fixture::jsonReport();

    expect($exitBefore)->toBe(0)
        ->and($before['phase'])->toBe('pre_migration')
        ->and($before['consistent'])->toBeNull()
        ->and($exitAfter)->toBe(0)
        ->and($after['phase'])->toBe('post_migration')
        ->and($after['consistent'])->toBeTrue()
        ->and($hasRangeCheck())->toBeTrue();

    foreach ($expected as $planSetId => $classification) {
        $prediction = $predicted[$planSetId]['prediction'];
        $trails = Fixture::trailsOf($planSetId);

        expect($predicted[$planSetId]['classification'])->toBe($classification->value)
            ->and($predicted[$planSetId]['baseline'])->toBeNull()
            ->and($recorded[$planSetId]['classification'])->toBe($classification->value)
            ->and($recorded[$planSetId]['consistent'])->toBeTrue();

        if ($prediction['outcome'] === 'skipped') {
            expect($trails)->toBeEmpty();

            continue;
        }

        $trail = $trails->sole();

        expect($trail->description)->toBe('initial_physical_progress_'.$prediction['outcome'])
            ->and(Fixture::decisionOf($prediction))
            ->toBe(Fixture::decisionOf(json_decode($trail->properties, true, flags: JSON_THROW_ON_ERROR)));
    }
});

it('reads inside a read-only transaction that MySQL itself enforces', function () {
    $planSetId = array_key_first(Fixture::scenario('proven on the first line'));
    Fixture::migration()->up();
    $operationId = (int) DB::table('measurement_plan_sets')->where('id', $planSetId)->value('operation_id');
    $title = DB::table('operations')->where('id', $operationId)->value('title');
    $timeline = [];
    $writeAttempt = null;

    Event::listen(TransactionBeginning::class, function () use (&$timeline): void {
        $timeline[] = 'begin';
    });
    Event::listen(TransactionCommitted::class, function () use (&$timeline): void {
        $timeline[] = 'commit';
    });
    Event::listen(TransactionRolledBack::class, function () use (&$timeline): void {
        $timeline[] = 'rollback';
    });

    DB::listen(function (QueryExecuted $query) use (&$timeline, &$writeAttempt, $operationId): void {
        $sql = strtolower(ltrim($query->sql));
        $timeline[] = $sql;

        // Uma escrita no meio do relatório, pela mesma conexão e dentro da
        // transação dele: quem precisa recusá-la é o servidor, não o código.
        if ($writeAttempt === null && DB::transactionLevel() === 1 && str_starts_with($sql, 'select')) {
            $writeAttempt = 'pending';

            try {
                DB::table('operations')->where('id', $operationId)->update(['title' => 'escrita durante o relatório']);
                $writeAttempt = 'accepted';
            } catch (QueryException $exception) {
                $writeAttempt = (int) ($exception->errorInfo[1] ?? 0);
            }
        }
    });

    $exitCode = Artisan::call('measurements:initial-progress-report', ['--json' => true]);

    $begin = array_search('begin', $timeline, true);
    $rollback = array_search('rollback', $timeline, true);

    // O SET é a 1ª instrução do comando e o begin vem logo depois: nenhuma
    // leitura -- nem a do esquema, que decide a fase -- roda fora da
    // fotografia READ ONLY.
    expect($exitCode)->toBe(0)
        ->and($writeAttempt)->toBe(1792)
        ->and($timeline[0] ?? null)->toBe('set transaction read only')
        ->and($begin)->toBe(1)
        ->and($rollback)->toBe(count($timeline) - 1)
        ->and(array_slice($timeline, $begin + 1, $rollback - $begin - 1))->not->toBeEmpty()->each->toStartWith('select')
        ->and($timeline)->not->toContain('commit')
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::table('operations')->where('id', $operationId)->value('title'))->toBe($title);
});

/**
 * Dentro de uma transação em curso, SET TRANSACTION é recusado (1568) e abrir
 * outra faria commit implícito da que estava aberta. O relatório só lê pelo
 * caminho comum, e o rollback de quem abriu continua desfazendo tudo.
 */
it('leaves a transaction already open untouched', function (string $case) {
    $planSetId = array_key_first(Fixture::scenario('proven on the first line'));
    Fixture::migration()->up();
    $operationId = (int) DB::table('measurement_plan_sets')->where('id', $planSetId)->value('operation_id');
    $title = DB::table('operations')->where('id', $operationId)->value('title');
    $statements = [];

    match ($case) {
        'opened by the application' => DB::beginTransaction(),
        'opened outside the query builder' => DB::unprepared('START TRANSACTION'),
    };

    try {
        DB::table('operations')->where('id', $operationId)->update(['title' => 'alteração ainda não confirmada']);

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        $exitCode = Artisan::call('measurements:initial-progress-report', ['--json' => true]);
        $levelAfterReport = DB::transactionLevel();
        $stillInTransaction = DB::connection()->getPdo()->inTransaction();
    } finally {
        match ($case) {
            'opened by the application' => DB::rollBack(),
            'opened outside the query builder' => DB::unprepared('ROLLBACK'),
        };
    }

    expect($exitCode)->toBe(0)
        ->and($levelAfterReport)->toBe($case === 'opened by the application' ? 1 : 0)
        ->and($stillInTransaction)->toBeTrue()
        ->and($statements)->not->toBeEmpty()
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'set transaction')))->toBe([])
        ->and(DB::table('operations')->where('id', $operationId)->value('title'))->toBe($title);
})->with(['opened by the application', 'opened outside the query builder']);
