<?php

use App\Models\Measurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * As migrations das revisões contra o DDL real do MySQL: no MySQL o DDL não se
 * desfaz, cada coluna acrescentada é um ALTER próprio e cada FK sem índice que
 * a sirva ganha um índice com o nome dela, que o DROP FOREIGN KEY deixa para
 * trás. O SQLite não mostra nada disso.
 */
pest()->group('mysql');

const REVISION_MIGRATIONS = [
    '2026_10_09_105904_add_revision_identity_to_measurements_table',
    '2026_10_09_105906_create_measurement_revision_differences_table',
];

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para o DDL real das migrations das revisões. Execute pelo scripts/parity-check.sh com este arquivo.');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de scripts/parity-check.sh.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    Notification::fake();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    foreach (REVISION_MIGRATIONS as $name) {
        revisionMigration($name)->up();
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

function revisionMigration(string $name): Migration
{
    return require database_path('migrations/'.$name.'.php');
}

function revisionMigrationsDown(): void
{
    foreach (array_reverse(REVISION_MIGRATIONS) as $name) {
        revisionMigration($name)->down();
    }
}

function revisionMigrationsUp(): void
{
    foreach (REVISION_MIGRATIONS as $name) {
        revisionMigration($name)->up();
    }
}

/**
 * @return list<string>
 */
function revisionIndexNames(string $table): array
{
    return collect(Schema::getIndexes($table))->pluck('name')->sort()->values()->all();
}

it('turns existing measurements into effective originals and survives repeated rollbacks and reruns', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $june = Scenario::awaitingPayment($scenario, '2026-06', 5);
    $indexesBefore = revisionIndexNames('measurements');
    $assetIndexesBefore = revisionIndexNames('measurement_assets');

    revisionMigrationsDown();

    expect(Schema::hasColumn('measurements', 'revision_family_id'))->toBeFalse()
        ->and(Schema::hasColumn('measurement_assets', 'inherited_from_asset_id'))->toBeFalse()
        ->and(Schema::hasTable('measurement_revision_differences'))->toBeFalse()
        // Nenhum índice que uma FK das revisões criou fica para trás.
        ->and(collect(revisionIndexNames('measurements'))->filter(fn (string $name): bool => str_starts_with($name, 'm_rev_'))->all())->toBe([])
        ->and(collect(revisionIndexNames('measurement_assets'))->filter(fn (string $name): bool => str_starts_with($name, 'ma_inherited') || $name === 'ma_id_context_unique')->all())->toBe([]);

    revisionMigrationsUp();
    revisionMigrationsUp();

    foreach ([$may, $june] as $measurement) {
        $row = DB::table('measurements')->where('id', $measurement->id)->first();

        expect((int) $row->revision_family_id)->toBe($measurement->id)
            ->and((int) $row->revision_number)->toBe(0)
            ->and($row->revision_status)->toBe('effective')
            ->and($row->revision_root_id)->toBeNull();
    }

    expect(revisionIndexNames('measurements'))->toBe($indexesBefore)
        ->and(revisionIndexNames('measurement_assets'))->toBe($assetIndexesBefore);

    // De novo, agora com as revisões funcionando sobre o esquema refeito.
    $revision = Scenario::effective($scenario, $may->fresh(), 12);

    expect($revision->revisionNumber())->toBe(1)
        ->and($may->fresh()->revision_status->value)->toBe('superseded');
});

it('refuses to roll back the revision identity once a revision exists', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    Scenario::revise($scenario, $may);

    expect(fn () => revisionMigration(REVISION_MIGRATIONS[0])->down())
        ->toThrow(RuntimeException::class, 'Existem revisões de medição registradas');

    expect(Schema::hasColumn('measurements', 'revision_family_id'))->toBeTrue()
        ->and(Measurement::query()->where('revision_number', 1)->exists())->toBeTrue();
});

it('completes a run interrupted between two columns and coexists with an index left behind by an older rollback', function () {
    revisionMigrationsDown();
    revisionMigrationsUp();

    // Interrompida no meio: a última coluna não chegou a entrar.
    revisionMigration(REVISION_MIGRATIONS[1])->down();
    Schema::table('measurements', fn ($table) => $table->dropColumn('previous_snapshot_sha256'));

    revisionMigrationsUp();

    expect(Schema::hasColumn('measurements', 'previous_snapshot_sha256'))->toBeTrue()
        ->and(Schema::hasTable('measurement_revision_differences'))->toBeTrue();

    // A reversão anterior ao índice explícito deixava o índice implícito da FK
    // do arquivo herdado sem a coluna herdada -- e ele passava a sustentar a FK
    // do plano. Simulado aqui como ficava; a migração convive com ele.
    revisionMigrationsDown();
    Schema::table('measurement_assets', fn ($table) => $table->index(['plan_set_id', 'plan_version_id', 'plan_line_id'], 'ma_inherited_context_foreign'));

    revisionMigrationsUp();
    revisionMigrationsDown();
    revisionMigrationsUp();

    expect(collect(Schema::getForeignKeys('measurement_assets'))->pluck('name')->all())->toContain('ma_inherited_context_foreign')
        ->and(revisionIndexNames('measurement_assets'))->toContain('ma_inherited_context_index');
});
